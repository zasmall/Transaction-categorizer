<?php

namespace App\Categorization\Ai;

use Anthropic\Client as AnthropicClient;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Claude through the official Anthropic PHP SDK, with JSON-schema structured
 * output and a cached system prompt.
 *
 * The SDK's own retries are turned off: the queue job already retries rate limits
 * and overloads with backoff, and two retry layers would multiply the attempts.
 */
class SdkClaudeCategorizer implements AiCategorizer
{
    public function __construct(
        private AnthropicClient $anthropic,
        private string $model,
        private int $maxTokens,
        private int $timeoutSeconds,
    ) {}

    public function suggest(Client $client, Collection $transactions, Collection $accounts, array $examples): AiSuggestionBatch
    {
        $message = $this->anthropic->messages->create(
            model: $this->model,
            maxTokens: $this->maxTokens,
            system: [[
                'type' => 'text',
                'text' => CategorizationPrompt::system($client, $accounts, $examples),
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            messages: [
                ['role' => 'user', 'content' => CategorizationPrompt::transactions($transactions)],
            ],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => CategorizationPrompt::schema()],
            ],
            requestOptions: ['timeout' => $this->timeoutSeconds, 'maxRetries' => 0],
        );

        $usage = [$message->usage->inputTokens, $message->usage->outputTokens];

        // A refusal or a truncated answer has no usable JSON: leave the chunk for people.
        if ($message->stopReason !== 'end_turn') {
            Log::warning('Claude returned no usable categorizations.', ['stop_reason' => $message->stopReason, 'model' => $this->model]);

            return new AiSuggestionBatch([], $this->model, ...$usage);
        }

        return new AiSuggestionBatch(
            suggestions: CategorizationPrompt::parse($this->decodeFirstText($message->content)),
            model: $this->model,
            inputTokens: $usage[0],
            outputTokens: $usage[1],
        );
    }

    /**
     * @param  array<mixed>  $content
     * @return array<mixed>
     */
    private function decodeFirstText(array $content): array
    {
        foreach ($content as $block) {
            if (is_object($block) && ($block->type ?? null) === 'text' && is_string($block->text ?? null)) {
                try {
                    $decoded = json_decode($block->text, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    return [];
                }

                return is_array($decoded) ? $decoded : [];
            }
        }

        return [];
    }
}
