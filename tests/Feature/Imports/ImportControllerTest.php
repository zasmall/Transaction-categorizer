<?php

use App\Enums\ClientRole;
use App\Enums\ImportStatus;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Import;
use App\Models\ImportProfile;
use App\Models\User;
use App\Services\ClientOnboardingService;
use Database\Seeders\ImportProfileSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(ImportProfileSeeder::class);

    $this->owner = User::factory()->create();
    $this->client = app(ClientOnboardingService::class)->create($this->owner, 'Northwind Coffee Co.');
    $this->bankAccount = $this->client->bankAccounts()->create([
        'name' => 'Operating Checking',
        'ledger_account_id' => $this->client->accounts()->where('code', '1000')->value('id'),
        'import_profile_id' => ImportProfile::query()->where('name', ImportProfileSeeder::CHASE_CHECKING)->value('id'),
    ]);
});

function chaseUpload(): UploadedFile
{
    return new UploadedFile(base_path('tests/Fixtures/statements/chase_checking.csv'), 'chase.csv', 'text/csv', null, true);
}

test('the dashboard lists the user\'s clients', function () {
    app(ClientOnboardingService::class)->create(User::factory()->create(), 'Someone Else LLC');

    $this->actingAs($this->owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('clients', 1)
            ->where('clients.0.slug', 'northwind-coffee-co')
            ->where('clients.0.role', ClientRole::Owner->value)
            ->where('clients.0.bank_accounts_count', 1),
        );
});

test('members see the imports page', function () {
    $bookkeeper = User::factory()->create();
    $this->client->users()->attach($bookkeeper, ['role' => ClientRole::Bookkeeper]);

    $this->actingAs($bookkeeper)
        ->get(route('clients.imports.index', $this->client))
        ->assertInertia(fn (Assert $page) => $page
            ->component('imports/Index')
            ->where('client.slug', $this->client->slug)
            ->has('bankAccounts', 1)
            ->where('bankAccounts.0.profile', ImportProfileSeeder::CHASE_CHECKING),
        );
});

test('non-members cannot see or upload to a client', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('clients.imports.index', $this->client))->assertForbidden();
    $this->actingAs($outsider)
        ->post(route('clients.imports.store', $this->client), ['bank_account_id' => $this->bankAccount->id, 'file' => chaseUpload()])
        ->assertForbidden();

    expect(Import::count())->toBe(0);
});

test('uploading a statement starts an import and shows its progress', function () {
    $response = $this->actingAs($this->owner)->post(route('clients.imports.store', $this->client), [
        'bank_account_id' => $this->bankAccount->id,
        'file' => chaseUpload(),
    ]);

    $import = Import::sole();
    $response->assertRedirect(route('clients.imports.show', [$this->client, $import]));

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->user_id)->toBe($this->owner->id)
        ->and($import->original_filename)->toBe('chase.csv');

    $this->actingAs($this->owner)
        ->get(route('clients.imports.show', [$this->client, $import]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('imports/Show')
            ->where('statementImport.imported_rows', 6)
            ->where('statementImport.is_finished', true)
            ->has('rows', 7)
            ->where('rows.0.status', 'failed')
            ->where('rows.0.error', 'Date "13/45/2026" does not match the expected format m/d/Y.')
            ->where('rows.1.amount', '-$5.75'),
        );
});

test('the bank account must belong to the client', function () {
    $other = app(ClientOnboardingService::class)->create($this->owner, 'Other Client');
    $foreignAccount = BankAccount::factory()->for($other)->create();

    $this->actingAs($this->owner)
        ->post(route('clients.imports.store', $this->client), ['bank_account_id' => $foreignAccount->id, 'file' => chaseUpload()])
        ->assertSessionHasErrors('bank_account_id');

    expect(Import::count())->toBe(0);
});

test('only csv files are accepted', function () {
    $this->actingAs($this->owner)
        ->post(route('clients.imports.store', $this->client), [
            'bank_account_id' => $this->bankAccount->id,
            'file' => UploadedFile::fake()->create('statement.pdf', 10, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');
});

test('re-uploading the same file asks for confirmation first', function () {
    Bus::fake();
    $upload = fn (array $extra = []) => $this->actingAs($this->owner)->post(
        route('clients.imports.store', $this->client),
        ['bank_account_id' => $this->bankAccount->id, 'file' => chaseUpload(), ...$extra],
    );

    $upload()->assertSessionHasNoErrors();
    $upload()->assertSessionHasErrors(['file' => 'This exact file was already imported into this account on '.now()->toFormattedDayDateString().'. Tick "Import anyway" to import it again.']);
    $upload(['allow_duplicate_file' => '1'])->assertSessionHasNoErrors();

    expect(Import::count())->toBe(2);
});

test('imports from another client cannot be viewed through this client', function () {
    $other = app(ClientOnboardingService::class)->create($this->owner, 'Other Client');
    $foreignImport = Import::factory()->for(BankAccount::factory()->for($other))->create(['client_id' => $other->id]);

    $this->actingAs($this->owner)
        ->get(route('clients.imports.show', [$this->client, $foreignImport]))
        ->assertNotFound();
});

test('guests are sent to log in', function () {
    $this->get(route('clients.imports.index', Client::first()))->assertRedirect(route('login'));
});
