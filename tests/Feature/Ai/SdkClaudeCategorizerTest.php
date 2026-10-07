<?php

use Anthropic\Client as AnthropicClient;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Categorization\Ai\AiCategorizer;
use App\Categorization\Ai\CategorizationPrompt;
use App\Categorization\Ai\SdkClaudeCategorizer;
use App\Enums\CategorizationMethod;
use App\Enums\CategorizationStatus;
use App\Jobs\Imports\SuggestCategoriesForChunk;
use App\Models\BankAccount;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClientOnboardingService;
use Tests\Support\FakeAnthropicTransport;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = app(ClientOnboardingService::class)->create($this->user, 'Northwind Coffee Co.');
    $this->bankAccount = BankAccount::factory()->for($this->client)->create();
    $this->transactions = collect([
        Transaction::factory()->for($this->bankAccount)->create(['client_id' => $this->client->id, 'payee_normalized' => 'Adobe Creative Cld', 'amount_cents' => -5999]),
        Transaction::factory()->for($this->bankAccount)->create(['client_id' => $this->client->id, 'payee_normalized' => 'Monthly Service Fee', 'amount_cents' => -1500]),
    ]);
    $this->accounts = $this->client->accounts()->orderBy('code')->get();
});

function sdkCategorizer(FakeAnthropicTransport $transport): SdkClaudeCategorizer
{
    return new SdkClaudeCategorizer(
        anthropic: new AnthropicClient(apiKey: 'test-key', requestOptions: ['transporter' => $transport]),
        model: 'claude-haiku-4-5',
        maxTokens: 16000,
        timeoutSeconds: 120,
    );
}

test('it sends the shared prompt with a cached system block and a JSON schema', function () {
    $transport = new FakeAnthropicTransport([FakeAnthropicTransport::message(['suggestions' => []])]);

    sdkCategorizer($transport)->suggest($this->client, $this->transactions, $this->accounts, [['payee' => 'Blue Bottle', 'account_code' => '6400']]);

    $body = $transport->body();
    expect($transport->requests)->toHaveCount(1)
        ->and((string) $transport->requests[0]->getUri())->toEndWith('/v1/messages')
        ->and($transport->requests[0]->getHeaderLine('x-api-key'))->toBe('test-key')
        ->and($body['model'])->toBe('claude-haiku-4-5')
        ->and($body['max_tokens'])->toBe(16000)
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['system'][0]['text'])->toBe(CategorizationPrompt::system($this->client, $this->accounts, [['payee' => 'Blue Bottle', 'account_code' => '6400']]))
        ->and($body['messages'][0]['content'])->toBe(CategorizationPrompt::transactions($this->transactions))
        ->and($body['output_config']['format'])->toBe(['type' => 'json_schema', 'schema' => CategorizationPrompt::schema()])
        ->and($body)->not->toHaveKeys(['temperature', 'thinking', 'tool_choice']);
});

test('it parses suggestions and token usage from the response', function () {
    $transport = new FakeAnthropicTransport([FakeAnthropicTransport::message(['suggestions' => [
        ['transaction_id' => $this->transactions[0]->id, 'account_code' => '6500', 'confidence' => 91, 'reason' => 'Design software'],
        ['transaction_id' => 'not a number', 'account_code' => '6100', 'confidence' => 50, 'reason' => 'Malformed'],
    ]], inputTokens: 1800, outputTokens: 120)]);

    $batch = sdkCategorizer($transport)->suggest($this->client, $this->transactions, $this->accounts, []);

    expect($batch->model)->toBe('claude-haiku-4-5')
        ->and($batch->inputTokens)->toBe(1800)
        ->and($batch->outputTokens)->toBe(120)
        ->and($batch->suggestions)->toHaveCount(1)
        ->and($batch->suggestions[0]->accountCode)->toBe('6500')
        ->and($batch->suggestions[0]->confidence)->toBe(91);
});

test('a refusal or truncated answer yields no suggestions but still records usage', function (string $stopReason) {
    $transport = new FakeAnthropicTransport([FakeAnthropicTransport::message(['suggestions' => []], stopReason: $stopReason, inputTokens: 900)]);

    $batch = sdkCategorizer($transport)->suggest($this->client, $this->transactions, $this->accounts, []);

    expect($batch->suggestions)->toBe([])
        ->and($batch->inputTokens)->toBe(900);
})->with(['refusal', 'max_tokens']);

test('the SDK does not retry on its own; the queue job decides', function () {
    $transport = new FakeAnthropicTransport([
        FakeAnthropicTransport::error(429, 'rate_limit_error'),
        FakeAnthropicTransport::message(['suggestions' => []]),
    ]);

    try {
        sdkCategorizer($transport)->suggest($this->client, $this->transactions, $this->accounts, []);
        $this->fail('Expected a rate limit exception.');
    } catch (RateLimitException $e) {
        expect($transport->requests)->toHaveCount(1)
            ->and(SuggestCategoriesForChunk::isTransient($e))->toBeTrue();
    }
});

test('authentication errors are not retried', function () {
    $transport = new FakeAnthropicTransport([FakeAnthropicTransport::error(401, 'authentication_error')]);

    expect(fn () => sdkCategorizer($transport)->suggest($this->client, $this->transactions, $this->accounts, []))
        ->toThrow(function (AuthenticationException $e) {
            expect(SuggestCategoriesForChunk::isTransient($e))->toBeFalse();
        });
});

test('the sdk driver records validated suggestions through the pipeline', function () {
    $transport = new FakeAnthropicTransport([FakeAnthropicTransport::message(['suggestions' => [
        ['transaction_id' => $this->transactions[0]->id, 'account_code' => '6500', 'confidence' => 91, 'reason' => 'Design software'],
        ['transaction_id' => $this->transactions[1]->id, 'account_code' => '0000', 'confidence' => 70, 'reason' => 'Invented code'],
    ]])]);
    config(['categorization.ai.driver' => 'sdk']);
    app()->instance(AnthropicClient::class, new AnthropicClient(apiKey: 'test-key', requestOptions: ['transporter' => $transport]));

    expect(app(AiCategorizer::class))->toBeInstanceOf(SdkClaudeCategorizer::class);

    $import = Import::factory()->for($this->bankAccount)->create(['client_id' => $this->client->id]);
    app()->call([new SuggestCategoriesForChunk($import, $this->transactions->pluck('id')->all()), 'handle']);

    $adobe = $this->transactions[0]->refresh();
    expect($adobe->categorization_status)->toBe(CategorizationStatus::Suggested)
        ->and($adobe->currentCategorization->method)->toBe(CategorizationMethod::Ai)
        ->and($adobe->currentCategorization->model)->toBe('claude-haiku-4-5')
        ->and($this->transactions[1]->refresh()->categorization_status)->toBe(CategorizationStatus::Uncategorized)
        ->and($import->refresh()->ai_input_tokens)->toBe(1200);
});
