<?php

namespace App\Categorization;

use App\Enums\CategorizationStatus;
use App\Models\Account;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Spots payees a person keeps approving to the same account, so they can be
 * turned into rules. Over time rules handle more, and the AI (and its cost)
 * handles less.
 */
class LearnedRuleSuggestions
{
    public const MIN_APPROVALS = 3;

    /**
     * @return Collection<int, array{payee: string, account_id: int, account: string, approvals: int}>
     */
    public function for(Client $client): Collection
    {
        $groups = Transaction::forClient($client)
            ->where('categorization_status', CategorizationStatus::Approved)
            ->whereNotNull('account_id')
            ->selectRaw('lower(payee_normalized) as payee_key, account_id, count(*) as approvals, max(id) as example_id')
            ->groupByRaw('lower(payee_normalized), account_id')
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'payee_key' => (string) data_get($row, 'payee_key'),
                'account_id' => (int) data_get($row, 'account_id'),
                'approvals' => (int) data_get($row, 'approvals'),
                'example_id' => (int) data_get($row, 'example_id'),
            ]);

        // A payee approved to more than one account is ambiguous: leave it to people.
        $accountsPerPayee = $groups->countBy('payee_key');
        $candidates = $groups->filter(fn (array $group) => $accountsPerPayee[$group['payee_key']] === 1
            && $group['approvals'] >= self::MIN_APPROVALS);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $examples = Transaction::query()->whereKey($candidates->pluck('example_id'))->get()->keyBy('id');
        $accounts = Account::query()->whereKey($candidates->pluck('account_id'))->where('is_active', true)->get()->keyBy('id');
        $engine = RuleEngine::forClient($client);

        return $candidates
            ->map(function (array $group) use ($examples, $accounts, $engine) {
                /** @var Transaction|null $example */
                $example = $examples->get($group['example_id']);
                /** @var Account|null $account */
                $account = $accounts->get($group['account_id']);

                // Skip payees an existing rule already sends to this account.
                if ($example === null || $account === null || $engine->firstMatch($example)?->account_id === $account->id) {
                    return null;
                }

                return [
                    'payee' => $example->payee_normalized,
                    'account_id' => $account->id,
                    'account' => "{$account->code} · {$account->name}",
                    'approvals' => $group['approvals'],
                ];
            })
            ->filter()
            ->sortByDesc('approvals')
            ->values();
    }
}
