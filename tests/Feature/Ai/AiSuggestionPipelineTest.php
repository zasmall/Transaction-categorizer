<?php

use App\Categorization\Ai\DemoCategorizer;
use App\Enums\CategorizationMethod;
use App\Enums\CategorizationStatus;
use App\Enums\ImportStatus;
use App\Jobs\Imports\FinalizeImport;
use App\Jobs\Imports\QueueAiSuggestions;
use App\Jobs\Imports\SuggestCategoriesForChunk;
use App\Models\CategorizationRule;
use App\Models\Import;
use App\Models\ImportProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClientOnboardingService;
use App\Services\ImportService;
use Database\Seeders\ImportProfileSeeder;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(ImportProfileSeeder::class);
    config(['categorization.ai.driver' => 'anthropic', 'categorization.ai.model' => 'claude-opus-5-5']);

    $this->user = User::factory()->create();
    $this->client = app(ClientOnboardingService::class)->create($this->user, 'Northwind Coffee Co.');
    $this->bankAccount = $this->client->bankAccounts()->create([
        'name' => 'Operating Checking',
        'ledger_account_id' => $this->client->accounts()->where('code', '1000')->value('id'),
        'import_profile_id' => ImportProfile::query()->where('name', ImportProfileSeeder::CHASE_CHECKING)->value('id'),
    ]);
});

function importChase(): Import
{
    $file = new UploadedFile(base_path('tests/Fixtures/statements/chase_checking.csv'), 'chase.csv', 'text/csv', null, true);

    return app(ImportService::class)->start(test()->bankAccount, $file, test()->user)->refresh();
}

/**
 * @param  list<array<string, mixed>>  $suggestions
 */
function claudeReplies(array $suggestions, int $inputTokens = 1200, int $outputTokens = 300): StructuredResponseFake
{
    return StructuredResponseFake::make()
        ->withStructured(['suggestions' => $suggestions])
        ->withUsage(new Usage($inputTokens, $outputTokens));
}

function txnId(string $payee, int $nth = 0): int
{
    return Transaction::where('payee_normalized', $payee)->orderBy('id')->skip($nth)->value('id');
}

test('transactions rules miss get validated AI suggestions, then the import finalizes', function () {
    $meals = $this->client->accounts()->where('code', '6400')->sole();
    CategorizationRule::factory()->for($this->client)->create(['account_id' => $meals->id, 'pattern' => 'blue bottle']);

    // Import with AI off first so the fake reply can reference real transaction ids,
    // then run the AI stage and finalize as a real chain.
    config(['categorization.ai.driver' => 'disabled']);
    $import = importChase();
    config(['categorization.ai.driver' => 'anthropic']);

    Prism::fake([claudeReplies([
        ['transaction_id' => txnId('Adobe Creative Cld'), 'account_code' => '6500', 'confidence' => 92, 'reason' => 'Design software subscription'],
        ['transaction_id' => txnId('Monthly Service Fee'), 'account_code' => '6100', 'confidence' => 88, 'reason' => 'Bank service charge'],
        ['transaction_id' => txnId('Staples Berkeley'), 'account_code' => '7777', 'confidence' => 70, 'reason' => 'Invented code'],
        ['transaction_id' => txnId('Blue Bottle Coffee Oakland'), 'account_code' => '6500', 'confidence' => 60, 'reason' => 'Not in this chunk'],
        ['transaction_id' => 999999, 'account_code' => '6500', 'confidence' => 70, 'reason' => 'Unknown transaction'],
    ])]);

    Bus::chain([new QueueAiSuggestions($import), new FinalizeImport($import)])->dispatch();
    $import->refresh();

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->categorized_rows)->toBe(2)
        ->and($import->ai_suggested_rows)->toBe(2)
        ->and($import->ai_model)->toBe('claude-opus-5-5')
        ->and($import->ai_input_tokens)->toBe(1200)
        ->and($import->ai_output_tokens)->toBe(300);

    $adobe = Transaction::find(txnId('Adobe Creative Cld'));
    expect($adobe->categorization_status)->toBe(CategorizationStatus::Suggested)
        ->and($adobe->account->code)->toBe('6500')
        ->and($adobe->currentCategorization->method)->toBe(CategorizationMethod::Ai)
        ->and($adobe->currentCategorization->confidence)->toBe(92)
        ->and($adobe->currentCategorization->ai_reason)->toBe('Design software subscription')
        ->and($adobe->currentCategorization->model)->toBe('claude-opus-5-5')
        ->and($adobe->currentCategorization->explanation())->toBe('AI suggestion (92% confident)');

    // An invented account code is dropped; the transaction waits for a person instead.
    expect(Transaction::find(txnId('Staples Berkeley'))->categorization_status)->toBe(CategorizationStatus::Uncategorized);

    // Rule-categorized transactions are never sent to or changed by the AI, even if it names them.
    expect(Transaction::where('payee_normalized', 'Blue Bottle Coffee Oakland')->pluck('account_id')->unique()->all())
        ->toBe([$meals->id]);
});

test('the request sends the chart, examples and transactions with a cached system prompt', function () {
    $fake = Prism::fake([claudeReplies([])]);
    $approved = Transaction::factory()->for($this->bankAccount)->create([
        'client_id' => $this->client->id,
        'payee_normalized' => 'Blue Bottle Coffee',
        'categorization_status' => CategorizationStatus::Approved,
        'account_id' => $this->client->accounts()->where('code', '6400')->value('id'),
    ]);

    importChase();

    $fake->assertCallCount(1);
    $fake->assertRequest(function (array $requests) {
        /** @var StructuredRequest $request */
        $request = $requests[0];
        $system = $request->systemPrompts()[0];

        expect($request->model())->toBe('claude-opus-5-5')
            ->and($request->maxTokens())->toBe(16000)
            ->and($request->temperature())->toBeNull()
            ->and($system->providerOptions('cacheType'))->toBe('ephemeral')
            ->and($system->content)->toContain('Northwind Coffee Co.')
            ->and($system->content)->toContain('6400 | Meals | Expense')
            ->and($system->content)->toContain('Blue Bottle Coffee -> 6400')
            ->and($request->prompt())->toContain('"payee":"Adobe Creative Cld"')
            ->and($request->prompt())->toContain('"amount":"-59.99"')
            ->and($request->schema()->toArray()['required'])->toBe(['suggestions']);
    });
});

test('large imports are split into chunks, one request each', function () {
    config(['categorization.ai.chunk_size' => 2]);
    $fake = Prism::fake([claudeReplies([]), claudeReplies([]), claudeReplies([])]);

    $import = importChase();

    $fake->assertCallCount(3);
    expect($import->status)->toBe(ImportStatus::CompletedWithErrors);
});

test('the AI batch is allowed to fail without failing the import', function () {
    $import = Import::factory()->for($this->bankAccount)->create(['client_id' => $this->client->id]);
    Transaction::factory()->for($this->bankAccount)->count(3)->create(['client_id' => $this->client->id, 'import_id' => $import->id]);

    $job = new QueueAiSuggestions($import);
    $job->handle();

    $batch = unserialize($job->chained[0]);
    expect($batch)->toBeInstanceOf(ChainedBatch::class)
        ->and($batch->toPendingBatch()->allowsFailures())->toBeTrue()
        ->and($batch->toPendingBatch()->jobs)->toHaveCount(1)
        ->and($batch->toPendingBatch()->jobs->first()->queue)->toBe('ai')
        ->and($import->refresh()->status)->toBe(ImportStatus::Suggesting);
});

test('chunk jobs are rate limited and retried for a while', function () {
    $job = new SuggestCategoriesForChunk(Import::factory()->for($this->bankAccount)->create(['client_id' => $this->client->id]), [1, 2]);

    expect(collect($job->middleware())->contains(fn ($middleware) => $middleware instanceof RateLimited))->toBeTrue()
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addMinutes(10)->getTimestamp())
        ->and($job->tags())->toContain("client:{$this->client->id}");
});

test('a chunk ignores transactions someone categorized after it was queued', function () {
    $fake = Prism::fake([claudeReplies([])]);
    $import = Import::factory()->for($this->bankAccount)->create(['client_id' => $this->client->id]);
    $done = Transaction::factory()->for($this->bankAccount)->create([
        'client_id' => $this->client->id,
        'categorization_status' => CategorizationStatus::Approved,
    ]);

    app()->call([new SuggestCategoriesForChunk($import, [$done->id]), 'handle']);

    $fake->assertCallCount(0);
});

test('the disabled driver skips AI entirely', function () {
    config(['categorization.ai.driver' => 'disabled']);
    $fake = Prism::fake();

    $import = importChase();

    $fake->assertCallCount(0);
    expect($import->ai_suggested_rows)->toBe(0)
        ->and(Transaction::where('categorization_status', CategorizationStatus::Suggested)->count())->toBe(0);
});

test('the demo driver suggests from keywords and labels itself', function () {
    config(['categorization.ai.driver' => 'demo']);
    $fake = Prism::fake();

    $import = importChase();

    $fake->assertCallCount(0);
    expect($import->ai_model)->toBe(DemoCategorizer::MODEL)
        ->and($import->ai_suggested_rows)->toBeGreaterThan(0);

    $coffee = Transaction::where('payee_normalized', 'Blue Bottle Coffee Oakland')->first();
    expect($coffee->categorization_status)->toBe(CategorizationStatus::Suggested)
        ->and($coffee->account->name)->toBe('Meals')
        ->and($coffee->currentCategorization->ai_reason)->toStartWith('Demo mode:');
});
