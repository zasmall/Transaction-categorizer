<?php

use App\Enums\CategorizationStatus;
use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Imports\Normalizing\TransactionNormalizer;
use App\Imports\Parsing\ParserRegistry;
use App\Imports\Parsing\StatementParser;
use App\Jobs\Imports\ApplyCategorizationRules;
use App\Jobs\Imports\FinalizeImport;
use App\Jobs\Imports\NormalizeImportRows;
use App\Jobs\Imports\ParseImportFile;
use App\Jobs\Imports\PersistImportedTransactions;
use App\Jobs\Imports\QueueAiSuggestions;
use App\Models\BankAccount;
use App\Models\Import;
use App\Models\ImportProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClientOnboardingService;
use App\Services\ImportService;
use Database\Seeders\ImportProfileSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(ImportProfileSeeder::class);

    $this->user = User::factory()->create();
    $this->client = app(ClientOnboardingService::class)->create($this->user, 'Northwind Coffee Co.');
});

function bankAccountUsing(string $profileName, string $ledgerCode = '1000'): BankAccount
{
    $client = test()->client;

    return $client->bankAccounts()->create([
        'name' => $profileName,
        'ledger_account_id' => $client->accounts()->where('code', $ledgerCode)->value('id'),
        'import_profile_id' => ImportProfile::query()->whereNull('client_id')->where('name', $profileName)->value('id'),
    ]);
}

function statementFixture(string $name): UploadedFile
{
    return new UploadedFile(base_path("tests/Fixtures/statements/{$name}"), $name, 'text/csv', null, true);
}

function csvUpload(string $contents, string $name = 'statement.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

function runImport(BankAccount $bankAccount, UploadedFile $file): Import
{
    return app(ImportService::class)->start($bankAccount, $file, test()->user)->refresh();
}

test('a checking statement is imported with bad rows set aside', function () {
    $import = runImport(bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING), statementFixture('chase_checking.csv'));

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->total_rows)->toBe(7)
        ->and($import->imported_rows)->toBe(6)
        ->and($import->duplicate_rows)->toBe(0)
        ->and($import->failed_rows)->toBe(1)
        ->and($import->started_at)->not->toBeNull()
        ->and($import->finished_at)->not->toBeNull();

    $failed = $import->rows()->where('status', ImportRowStatus::Failed)->sole();
    expect($failed->row_number)->toBe(8)
        ->and($failed->error)->toBe('Date "13/45/2026" does not match the expected format m/d/Y.')
        ->and($failed->raw['Description'])->toBe('PG&E WEB ONLINE');

    $transactions = Transaction::forClient($this->client)->orderBy('id')->get();
    expect($transactions)->toHaveCount(6)
        ->and($transactions->pluck('amount_cents')->all())->toBe([-575, -575, 245000, -5999, -123456, -1500])
        ->and($transactions[0]->payee_normalized)->toBe('Blue Bottle Coffee Oakland')
        ->and($transactions[0]->posted_on->toDateString())->toBe('2026-01-05')
        ->and($transactions->every(fn (Transaction $t) => $t->categorization_status === CategorizationStatus::Uncategorized))->toBeTrue()
        ->and($transactions->every(fn (Transaction $t) => $t->import_row_id !== null))->toBeTrue();
});

test('re-importing the same file creates no new transactions', function () {
    $bankAccount = bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING);
    runImport($bankAccount, statementFixture('chase_checking.csv'));

    $again = runImport($bankAccount, statementFixture('chase_checking.csv'));

    expect($again->imported_rows)->toBe(0)
        ->and($again->duplicate_rows)->toBe(6)
        ->and(Transaction::count())->toBe(6);
});

test('an overlapping statement only adds the new transactions', function () {
    $bankAccount = bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING);
    $header = "Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #\n";
    $coffee = "DEBIT,01/05/2026,SQ *BLUE BOTTLE COFFEE,-5.75,DEBIT_CARD,0,,\n";

    runImport($bankAccount, csvUpload($header.$coffee.$coffee, 'first.csv'));
    $second = runImport($bankAccount, csvUpload($header.$coffee.$coffee.$coffee."DEBIT,01/06/2026,RENT,-1500.00,ACH_DEBIT,0,,\n", 'second.csv'));

    expect($second->duplicate_rows)->toBe(2)
        ->and($second->imported_rows)->toBe(2)
        ->and(Transaction::where('amount_cents', -575)->count())->toBe(3);
});

test('the same transaction in a different bank account is not a duplicate', function () {
    runImport(bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING), statementFixture('chase_checking.csv'));
    $other = runImport(bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING, '1010'), statementFixture('chase_checking.csv'));

    expect($other->imported_rows)->toBe(6);
});

test('debit and credit columns are converted to signed amounts', function () {
    $import = runImport(bankAccountUsing(ImportProfileSeeder::CAPITAL_ONE_CARD, '2100'), statementFixture('capital_one_card.csv'));

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->transactions()->orderBy('id')->pluck('amount_cents')->all())->toBe([-2318, -6407, 50000, 1999]);
});

test('inverted card statements with a byte order mark are imported', function () {
    $import = runImport(bankAccountUsing(ImportProfileSeeder::AMEX_CARD, '2100'), statementFixture('amex_card.csv'));

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->transactions()->orderBy('id')->pluck('amount_cents')->all())->toBe([-41230, -8645, 49875])
        ->and($import->transactions()->orderBy('id')->value('payee_normalized'))->toBe('Delta Air Lines Atlanta');
});

test('a file in the wrong format fails the import with a helpful message', function () {
    $import = runImport(bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING), statementFixture('wrong_format.csv'));

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toContain('Missing expected column(s): Posting Date, Description, Amount.')
        ->and($import->rows()->count())->toBe(0)
        ->and($import->finished_at)->not->toBeNull()
        ->and(Transaction::count())->toBe(0);
});

test('a file with only a header row fails the import', function () {
    $import = runImport(
        bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING),
        csvUpload("Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #\n"),
    );

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe('The file has no transaction rows.');
});

test('an unexpected error marks the import failed', function () {
    app()->instance(ParserRegistry::class, new ParserRegistry(['csv' => new class implements StatementParser
    {
        public function parse(string $path, ImportProfile $profile): iterable
        {
            throw new RuntimeException('Disk on fire');
        }
    }]));

    $bankAccount = bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING);

    expect(fn () => runImport($bankAccount, statementFixture('chase_checking.csv')))->toThrow(RuntimeException::class, 'Disk on fire');

    $import = Import::sole();
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toContain('Something went wrong');
});

test('every stage is safe to run again', function () {
    $import = runImport(bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING), statementFixture('chase_checking.csv'));
    $rowIds = $import->rows()->pluck('id')->all();

    (new ParseImportFile($import))->handle(app(ParserRegistry::class));
    (new NormalizeImportRows($import))->handle(app(TransactionNormalizer::class));
    (new PersistImportedTransactions($import))->handle();
    (new FinalizeImport($import))->handle();

    expect($import->rows()->pluck('id')->all())->toBe($rowIds)
        ->and(Transaction::count())->toBe(6)
        ->and(Transaction::whereNull('import_row_id')->count())->toBe(0)
        ->and($import->refresh()->imported_rows)->toBe(6)
        ->and($import->duplicate_rows)->toBe(0)
        ->and($import->failed_rows)->toBe(1);
});

test('a partially parsed import is restaged from scratch', function () {
    Bus::fake();
    $import = runImport(bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING), statementFixture('chase_checking.csv'));
    $import->rows()->create(['row_number' => 2, 'raw' => ['partial' => 'yes'], 'status' => ImportRowStatus::Pending]);

    (new ParseImportFile($import))->handle(app(ParserRegistry::class));

    expect($import->rows()->count())->toBe(7)
        ->and($import->rows()->where('row_number', 2)->sole()->raw)->not->toHaveKey('partial')
        ->and($import->refresh()->total_rows)->toBe(7);
});

test('the pipeline is queued as a chain on the imports queue', function () {
    Bus::fake();

    $import = runImport(bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING), statementFixture('chase_checking.csv'));

    expect($import->status)->toBe(ImportStatus::Pending);
    Storage::disk('local')->assertExists($import->stored_path);

    Bus::assertChained([
        ParseImportFile::class,
        NormalizeImportRows::class,
        PersistImportedTransactions::class,
        ApplyCategorizationRules::class,
        QueueAiSuggestions::class,
        FinalizeImport::class,
    ]);

    Bus::assertDispatched(ParseImportFile::class, fn (ParseImportFile $job) => $job->queue === 'imports'
        && $job->tags() === ["import:{$import->id}", "client:{$this->client->id}"]);
});

test('an earlier import of the same file can be found', function () {
    $bankAccount = bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING);
    $service = app(ImportService::class);

    expect($service->previousImportOf($bankAccount, statementFixture('chase_checking.csv')))->toBeNull();

    $first = runImport($bankAccount, statementFixture('chase_checking.csv'));

    expect($service->previousImportOf($bankAccount, statementFixture('chase_checking.csv'))?->id)->toBe($first->id)
        ->and($service->previousImportOf($bankAccount, statementFixture('amex_card.csv')))->toBeNull();
});

test('rules categorize new transactions during the import', function () {
    $meals = $this->client->accounts()->where('code', '6400')->sole();
    $this->client->rules()->create([
        'name' => 'Coffee shops',
        'match_field' => 'payee',
        'operator' => 'contains',
        'pattern' => 'blue bottle',
        'account_id' => $meals->id,
    ]);

    $import = runImport(bankAccountUsing(ImportProfileSeeder::CHASE_CHECKING), statementFixture('chase_checking.csv'));

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->categorized_rows)->toBe(2)
        ->and(Transaction::where('account_id', $meals->id)->count())->toBe(2)
        ->and(Transaction::where('categorization_status', CategorizationStatus::Uncategorized)->count())->toBe(4);
});
