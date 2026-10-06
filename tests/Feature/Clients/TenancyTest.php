<?php

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\ImportProfile;
use Illuminate\Database\UniqueConstraintViolationException;

test('forClient only returns the given client\'s records', function () {
    [$mine, $theirs] = Client::factory()->count(2)->create();
    Account::factory()->count(2)->for($mine)->create();
    Account::factory()->count(3)->for($theirs)->create();

    expect(Account::forClient($mine)->count())->toBe(2)
        ->and(Account::forClient($mine)->pluck('client_id')->unique()->all())->toBe([$mine->id]);
});

test('account codes are unique per client but may repeat across clients', function () {
    [$first, $second] = Client::factory()->count(2)->create();
    Account::factory()->for($first)->create(['code' => '6400']);
    Account::factory()->for($second)->create(['code' => '6400']);

    expect(fn () => Account::factory()->for($first)->create(['code' => '6400']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a client sees system default import profiles plus its own', function () {
    [$mine, $theirs] = Client::factory()->count(2)->create();
    $system = ImportProfile::factory()->create(['name' => 'System']);
    $own = ImportProfile::factory()->for($mine)->create(['name' => 'Mine']);
    ImportProfile::factory()->for($theirs)->create(['name' => 'Theirs']);

    expect(ImportProfile::availableTo($mine)->pluck('id')->sort()->values()->all())
        ->toBe([$system->id, $own->id])
        ->and($system->isSystemDefault())->toBeTrue()
        ->and($own->isSystemDefault())->toBeFalse();
});

test('the bank account factory keeps the ledger account on the same client', function () {
    $bankAccount = BankAccount::factory()->create();

    expect($bankAccount->ledgerAccount->client_id)->toBe($bankAccount->client_id)
        ->and($bankAccount->ledgerAccount->type->canBackBankAccount())->toBeTrue();
});
