<?php

use App\Categorization\LearnedRuleSuggestions;
use App\Enums\CategorizationStatus;
use App\Enums\ImportStatus;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\ImportProfile;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ImportProfileSeeder;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Facades\Prism;

beforeEach(fn () => Storage::fake('local'));

test('the database seeder builds a usable demo without calling any AI service', function () {
    config(['categorization.ai.driver' => 'anthropic']);
    $prism = Prism::fake();

    $this->seed(DatabaseSeeder::class);

    $prism->assertCallCount(0);
    expect(config('categorization.ai.driver'))->toBe('anthropic')
        ->and(config('queue.default'))->toBe('sync');

    $demo = User::where('email', DemoSeeder::DEMO_EMAIL)->firstOrFail();
    expect($demo->clients)->toHaveCount(2)
        ->and(ImportProfile::whereNull('client_id')->count())->toBe(3)
        ->and(BankAccount::count())->toBe(4);

    BankAccount::with('ledgerAccount')->get()->each(
        fn (BankAccount $bankAccount) => expect($bankAccount->ledgerAccount->client_id)->toBe($bankAccount->client_id),
    );

    $this->post(route('login.store'), ['email' => DemoSeeder::DEMO_EMAIL, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
});

test('the demo tells the import, review and export story', function () {
    $this->seed(DatabaseSeeder::class);
    $northwind = Client::where('slug', 'northwind-coffee-co')->sole();
    $imports = $northwind->imports()->orderBy('id')->get()->keyBy('original_filename');

    // January was reviewed and exported; nothing in it is waiting.
    $january = $imports['northwind-checking-2026-01.csv'];
    expect($january->transactions()->where('categorization_status', '!=', CategorizationStatus::Approved)->count())->toBe(0)
        ->and($january->transactions()->whereNull('exported_at')->count())->toBe(0);

    // February repeats two January rows; March has broken rows.
    expect($imports['northwind-checking-2026-02.csv']->duplicate_rows)->toBe(2)
        ->and($imports['northwind-checking-2026-03.csv']->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($imports['northwind-checking-2026-03.csv']->failed_rows)->toBe(2);

    // Work is waiting in review, and a correction has turned into a rule suggestion.
    expect(Transaction::forClient($northwind)->where('categorization_status', CategorizationStatus::Suggested)->count())->toBeGreaterThan(0)
        ->and(app(LearnedRuleSuggestions::class)->for($northwind)->pluck('payee')->all())->toContain('Pacific Coffee Roasters Inv');
});

test('the import profile seeder can be re-run safely', function () {
    $this->seed(ImportProfileSeeder::class);
    $this->seed(ImportProfileSeeder::class);

    expect(ImportProfile::count())->toBe(3)
        ->and(Client::count())->toBe(0);
});
