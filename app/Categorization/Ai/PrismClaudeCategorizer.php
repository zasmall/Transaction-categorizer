<?php

namespace App\Categorization\Ai;

use App\Models\Client;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

/**
 * Claude through Prism, Laravel's provider-agnostic LLM package, using its native
 * JSON-schema structured output.
 */
class PrismClaudeCategorizer implements AiCategorizer
{
    public function __construct(
        private string $model,
        private int $maxTokens,
        private int $timeoutSeconds,
    ) {}

    public function suggest(Client $client, Collection $transactions, Collection $accounts, array $examples): AiSuggestionBatch
    {
        $response = Prism::structured()
            ->using(Provider::Anthropic, $this->model)
            ->withSystemPrompt(
                (new SystemMessage(CategorizationPrompt::system($client, $accounts, $examples)))
                    ->withProviderOptions(['cacheType' => 'ephemeral']),
            )
            ->withPrompt(CategorizationPrompt::transactions($transactions))
            ->withSchema(new RawSchema('categorizations', CategorizationPrompt::schema()))
            ->withMaxTokens($this->maxTokens)
            ->withClientOptions(['timeout' => $this->timeoutSeconds])
            ->asStructured();

        return new AiSuggestionBatch(
            suggestions: CategorizationPrompt::parse($response->structured),
            model: $this->model,
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
        );
    }
}
