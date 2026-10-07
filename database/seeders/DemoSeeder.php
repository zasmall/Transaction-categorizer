<?php

namespace Database\Seeders;

use App\Enums\CategorizationStatus;
use App\Enums\ClientRole;
use App\Enums\RuleMatchField;
use App\Enums\RuleOperator;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Import;
use App\Models\ImportProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\ClientOnboardingService;
use App\Services\ImportService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * A ready-to-explore demo (demo@example.com / password).
 *
 * Northwind Coffee Co. has January imported, reviewed and exported; February
 * (which overlaps January), March (with a few broken rows) and the card statement
 * are imported and waiting in Review. Bright Path Consulting has a quarter of
 * statements imported and untouched.
 *
 * Imports run synchronously here with the demo categorizer, so seeding never
 * calls a paid API whatever AI_CATEGORIZER is set to.
 */
class DemoSeeder extends Seeder
{
    public const DEMO_EMAIL = 'demo@example.com';

    private const STATEMENTS = __DIR__.'/statements';

    private User $demo;

    public function __construct(
        private ClientOnboardingService $onboarding,
        private ImportService $imports,
        private CategorizationService $categorizations,
    ) {}

    public function run(): void
    {
        $queue = config('queue.default');
        $driver = config('categorization.ai.driver');
        config(['queue.default' => 'sync', 'categorization.ai.driver' => 'demo']);

        try {
            $this->demo = User::factory()->create(['name' => 'Demo Bookkeeper', 'email' => self::DEMO_EMAIL]);

            $this->northwind();
            $this->brightPath();
        } finally {
            config(['queue.default' => $queue, 'categorization.ai.driver' => $driver]);
        }
    }

    private function northwind(): void
    {
        $client = $this->onboarding->create($this->demo, 'Northwind Coffee Co.');
        $checking = $this->addBankAccount($client, 'Operating Checking', 'Chase', '4821', '1000', ImportProfileSeeder::CHASE_CHECKING);
        $card = $this->addBankAccount($client, 'Business Card', 'Capital One', '9934', '2100', ImportProfileSeeder::CAPITAL_ONE_CARD);

        $client->accounts()->where('code', '5000')->update(['qbo_name' => 'Cost of Goods Sold:Ingredients & Supplies']);

        $this->addRule($client, 'Rent', RuleMatchField::Description, RuleOperator::Contains, 'property mgmt rent', '6700', priority: 10);
        $this->addRule($client, 'Square card sales', RuleMatchField::Payee, RuleOperator::StartsWith, 'square inc deposit', '4000');
        $this->addRule($client, 'Sysco food supplies', RuleMatchField::Payee, RuleOperator::Contains, 'sysco', '5000');

        // January: imported, reviewed, then exported to QuickBooks.
        $january = $this->import($checking, 'northwind-checking-2026-01.csv');
        $this->reviewEverything($client, $january, corrections: [
            // The keyword matcher thinks a coffee roaster is "Meals"; the bookkeeper knows it's inventory.
            'pacific coffee roasters' => '5000',
            'costco' => '5000',
            'home depot' => '6500',
        ]);
        $january->transactions()->update(['exported_at' => now()]);

        // A second staff member with bookkeeper (non-owner) access.
        $assistant = User::factory()->create(['name' => 'Assistant Bookkeeper', 'email' => 'assistant@example.com']);
        $client->users()->attach($assistant, ['role' => ClientRole::Bookkeeper]);

        // February overlaps January by two rows; March has a few broken rows.
        $this->import($checking, 'northwind-checking-2026-02.csv');
        $this->import($checking, 'northwind-checking-2026-03.csv');
        $this->import($card, 'northwind-card-2026-q1.csv');
    }

    private function brightPath(): void
    {
        $client = $this->onboarding->create($this->demo, 'Bright Path Consulting LLC', fiscalYearStart: 7);
        $checking = $this->addBankAccount($client, 'Operating Checking', 'Chase', '1177', '1000', ImportProfileSeeder::CHASE_CHECKING);
        $amex = $this->addBankAccount($client, 'Amex Business Gold', 'American Express', '3005', '2100', ImportProfileSeeder::AMEX_CARD);

        $this->addRule($client, 'Stripe payouts', RuleMatchField::Payee, RuleOperator::StartsWith, 'stripe transfer', '4100');

        $this->import($checking, 'brightpath-checking-2026-q1.csv');
        $this->import($amex, 'brightpath-amex-2026-q1.csv');
    }

    /**
     * Approve every suggestion and categorize the leftovers, as a bookkeeper would.
     *
     * @param  array<string, string>  $corrections  payee fragment => account code
     */
    private function reviewEverything(Client $client, Import $import, array $corrections): void
    {
        $accounts = $client->accounts()->get()->keyBy('code');

        $import->transactions()
            ->whereIn('categorization_status', [CategorizationStatus::Suggested, CategorizationStatus::Uncategorized])
            ->with('account')
            ->get()
            ->each(function (Transaction $transaction) use ($accounts, $corrections) {
                $code = collect($corrections)->first(
                    fn (string $code, string $fragment) => str_contains(mb_strtolower($transaction->payee_normalized), $fragment),
                );

                $account = $code !== null ? $accounts[$code] : ($transaction->account ?? $accounts['9999']);
                $this->categorizations->categorizeManually($transaction, $account, $this->demo);
            });
    }

    private function import(BankAccount $bankAccount, string $file): Import
    {
        $upload = new UploadedFile(self::STATEMENTS.'/'.$file, $file, 'text/csv', null, true);

        return $this->imports->start($bankAccount, $upload, $this->demo)->refresh();
    }

    private function addBankAccount(Client $client, string $name, string $institution, string $last4, string $ledgerCode, string $profileName): BankAccount
    {
        return $client->bankAccounts()->create([
            'name' => $name,
            'institution' => $institution,
            'last4' => $last4,
            'ledger_account_id' => $client->accounts()->where('code', $ledgerCode)->value('id'),
            'import_profile_id' => ImportProfile::query()->whereNull('client_id')->where('name', $profileName)->value('id'),
        ]);
    }

    private function addRule(Client $client, string $name, RuleMatchField $field, RuleOperator $operator, string $pattern, string $accountCode, int $priority = 100): void
    {
        $client->rules()->create([
            'name' => $name,
            'match_field' => $field,
            'operator' => $operator,
            'pattern' => $pattern,
            'account_id' => $client->accounts()->where('code', $accountCode)->value('id'),
            'priority' => $priority,
        ]);
    }
}
