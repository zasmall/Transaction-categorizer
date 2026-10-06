<?php

use App\Models\BankAccount;
use App\Models\Client;
use App\Models\ImportProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ImportProfileSeeder;

test('the database seeder builds a usable demo', function () {
    $this->seed(DatabaseSeeder::class);

    $demo = User::where('email', DemoSeeder::DEMO_EMAIL)->firstOrFail();

    expect($demo->clients)->toHaveCount(2)
        ->and(ImportProfile::whereNull('client_id')->count())->toBe(3)
        ->and(BankAccount::count())->toBe(4);

    BankAccount::with('ledgerAccount', 'importProfile')->get()->each(function (BankAccount $bankAccount) {
        expect($bankAccount->ledgerAccount->client_id)->toBe($bankAccount->client_id)
            ->and($bankAccount->importProfile)->not->toBeNull();
    });

    $this->post(route('login.store'), ['email' => DemoSeeder::DEMO_EMAIL, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
});

test('the import profile seeder can be re-run safely', function () {
    $this->seed(ImportProfileSeeder::class);
    $this->seed(ImportProfileSeeder::class);

    expect(ImportProfile::count())->toBe(3)
        ->and(Client::count())->toBe(0);
});
