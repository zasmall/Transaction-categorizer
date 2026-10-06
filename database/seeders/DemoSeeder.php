<?php

namespace Database\Seeders;

use App\Enums\ClientRole;
use App\Models\Client;
use App\Models\ImportProfile;
use App\Models\User;
use App\Services\ClientOnboardingService;
use Illuminate\Database\Seeder;

/**
 * Demo login (demo@example.com / password) with two clients ready for imports.
 */
class DemoSeeder extends Seeder
{
    public const DEMO_EMAIL = 'demo@example.com';

    public function __construct(private ClientOnboardingService $onboarding) {}

    public function run(): void
    {
        $demo = User::factory()->create([
            'name' => 'Demo Bookkeeper',
            'email' => self::DEMO_EMAIL,
        ]);

        $coffee = $this->onboarding->create($demo, 'Northwind Coffee Co.');
        $this->addBankAccount($coffee, 'Operating Checking', 'Chase', '4821', '1000', ImportProfileSeeder::CHASE_CHECKING);
        $this->addBankAccount($coffee, 'Business Card', 'Capital One', '9934', '2100', ImportProfileSeeder::CAPITAL_ONE_CARD);

        $consulting = $this->onboarding->create($demo, 'Bright Path Consulting LLC', fiscalYearStart: 7);
        $this->addBankAccount($consulting, 'Operating Checking', 'Chase', '1177', '1000', ImportProfileSeeder::CHASE_CHECKING);
        $this->addBankAccount($consulting, 'Amex Business Gold', 'American Express', '3005', '2100', ImportProfileSeeder::AMEX_CARD);

        // A second staff member with bookkeeper (non-owner) access to one client.
        $assistant = User::factory()->create([
            'name' => 'Assistant Bookkeeper',
            'email' => 'assistant@example.com',
        ]);
        $coffee->users()->attach($assistant, ['role' => ClientRole::Bookkeeper]);
    }

    private function addBankAccount(Client $client, string $name, string $institution, string $last4, string $ledgerCode, string $profileName): void
    {
        $client->bankAccounts()->create([
            'name' => $name,
            'institution' => $institution,
            'last4' => $last4,
            'ledger_account_id' => $client->accounts()->where('code', $ledgerCode)->value('id'),
            'import_profile_id' => ImportProfile::query()->whereNull('client_id')->where('name', $profileName)->value('id'),
        ]);
    }
}
