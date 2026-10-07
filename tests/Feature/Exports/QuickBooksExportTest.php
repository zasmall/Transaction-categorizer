<?php

use App\Enums\CategorizationStatus;
use App\Exports\QuickBooksJournalCsv;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\ClientOnboardingService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = app(ClientOnboardingService::class)->create($this->user, 'Northwind Coffee Co.');
    $this->checking = $this->client->bankAccounts()->create([
        'name' => 'Operating Checking',
        'ledger_account_id' => $this->client->accounts()->where('code', '1000')->value('id'),
    ]);
    $this->meals = $this->client->accounts()->where('code', '6400')->sole();
    $this->revenue = $this->client->accounts()->where('code', '4100')->sole();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function approved(int $cents, string $date, Account $account, array $attributes = []): Transaction
{
    $transaction = Transaction::factory()->for($attributes['bank_account'] ?? test()->checking)->create([
        'client_id' => test()->client->id,
        'amount_cents' => $cents,
        'posted_on' => $date,
        'payee_normalized' => $attributes['payee'] ?? 'Blue Bottle Coffee',
        'description_raw' => 'SQ *BLUE BOTTLE',
    ]);
    app(CategorizationService::class)->categorizeManually($transaction, $account, test()->user);

    return $transaction->refresh();
}

/**
 * @return list<list<string>>
 */
function csvRows(string $csv): array
{
    return array_map(str_getcsv(...), array_filter(explode("\n", trim($csv))));
}

test('money out debits the category and credits the bank account', function () {
    $coffee = approved(-575, '2026-01-05', $this->meals);

    expect((new QuickBooksJournalCsv)->lines($coffee->load('bankAccount.ledgerAccount', 'account')))->toBe([
        ["TC-{$coffee->id}", '01/05/2026', 'Meals', '5.75', '', 'Blue Bottle Coffee', 'SQ *BLUE BOTTLE'],
        ["TC-{$coffee->id}", '01/05/2026', 'Business Checking', '', '5.75', 'Blue Bottle Coffee', 'SQ *BLUE BOTTLE'],
    ]);
});

test('money in debits the bank account and credits the category, using QuickBooks names', function () {
    $this->revenue->update(['qbo_name' => 'Services']);
    $deposit = approved(245000, '2026-01-06', $this->revenue, ['payee' => 'Stripe Transfer']);

    [$debit, $credit] = (new QuickBooksJournalCsv)->lines($deposit->load('bankAccount.ledgerAccount', 'account'));

    expect([$debit[2], $debit[3], $debit[4]])->toBe(['Business Checking', '2450.00', ''])
        ->and([$credit[2], $credit[3], $credit[4]])->toBe(['Services', '', '2450.00']);
});

test('the download contains only approved, not-yet-exported transactions in range', function () {
    $january = approved(-575, '2026-01-05', $this->meals);
    approved(-1000, '2026-02-01', $this->meals);
    $exported = approved(-200, '2026-01-10', $this->meals);
    $exported->update(['exported_at' => now()]);
    Transaction::factory()->for($this->checking)->create(['client_id' => $this->client->id, 'posted_on' => '2026-01-07']);

    $response = $this->actingAs($this->user)
        ->get(route('clients.exports.download', [$this->client, 'from' => '2026-01-01', 'to' => '2026-01-31']))
        ->assertOk()
        ->assertDownload('northwind-coffee-co-quickbooks-journal-'.now()->format('Y-m-d').'.csv');

    $rows = csvRows($response->streamedContent());
    expect($rows[0])->toBe(QuickBooksJournalCsv::HEADERS)
        ->and($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe("TC-{$january->id}");

    // Downloading has no side effects.
    expect($january->refresh()->exported_at)->toBeNull();
});

test('debits and credits balance across the whole file', function () {
    approved(-575, '2026-01-05', $this->meals);
    approved(245000, '2026-01-06', $this->revenue);
    approved(-123456, '2026-01-08', $this->meals);

    $rows = array_slice(csvRows($this->actingAs($this->user)->get(route('clients.exports.download', $this->client))->streamedContent()), 1);

    $debits = array_sum(array_map(fn (array $row) => (float) ($row[3] ?: 0), $rows));
    $credits = array_sum(array_map(fn (array $row) => (float) ($row[4] ?: 0), $rows));

    expect($rows)->toHaveCount(6)
        ->and(round($debits, 2))->toBe(round($credits, 2));
});

test('the export page previews what will be included', function () {
    approved(-575, '2026-01-05', $this->meals);
    approved(-575, '2026-01-06', $this->meals)->update(['exported_at' => now()]);
    Transaction::factory()->for($this->checking)->create(['client_id' => $this->client->id, 'categorization_status' => CategorizationStatus::Uncategorized]);

    $this->actingAs($this->user)
        ->get(route('clients.exports.index', $this->client))
        ->assertInertia(fn (Assert $page) => $page
            ->component('exports/Index')
            ->where('summary.ready', 1)
            ->where('summary.needs_review', 1)
            ->where('summary.already_exported', 1),
        );
});

test('marking as exported stamps the batch so the next export skips it', function () {
    $coffee = approved(-575, '2026-01-05', $this->meals);

    $this->actingAs($this->user)
        ->post(route('clients.exports.mark', $this->client))
        ->assertInertiaFlash('toast.message', 'Marked 1 transaction as exported.');

    expect($coffee->refresh()->exported_at)->not->toBeNull();

    $rows = csvRows($this->actingAs($this->user)->get(route('clients.exports.download', $this->client))->streamedContent());
    expect($rows)->toHaveCount(1);

    $again = csvRows($this->actingAs($this->user)->get(route('clients.exports.download', [$this->client, 'include_exported' => 1]))->streamedContent());
    expect($again)->toHaveCount(3);
});

test('exports can be limited to one bank account', function () {
    $card = BankAccount::factory()->for($this->client)->create(['name' => 'Card']);
    approved(-575, '2026-01-05', $this->meals);
    approved(-999, '2026-01-05', $this->meals, ['bank_account' => $card]);

    $rows = csvRows($this->actingAs($this->user)->get(route('clients.exports.download', [$this->client, 'bank_account_id' => $card->id]))->streamedContent());

    expect($rows)->toHaveCount(3)
        ->and($rows[1][3])->toBe('9.99');
});

test('export filters are validated and scoped to the client', function () {
    $other = app(ClientOnboardingService::class)->create($this->user, 'Other Client');
    $foreignAccount = BankAccount::factory()->for($other)->create();

    $this->actingAs($this->user)
        ->get(route('clients.exports.download', [$this->client, 'bank_account_id' => $foreignAccount->id]))
        ->assertSessionHasErrors('bank_account_id');
    $this->actingAs($this->user)
        ->get(route('clients.exports.download', [$this->client, 'from' => '2026-02-01', 'to' => '2026-01-01']))
        ->assertSessionHasErrors('to');
});

test('outsiders cannot export', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('clients.exports.download', $this->client))->assertForbidden();
    $this->actingAs($outsider)->post(route('clients.exports.mark', $this->client))->assertForbidden();
});
