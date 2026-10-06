<?php

namespace App\Categorization;

use App\Models\CategorizationRule;
use App\Models\Client;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Finds the first matching rule for a transaction. Rules are tried in priority
 * order (lowest first, then oldest), so behaviour is deterministic.
 */
class RuleEngine
{
    /**
     * @param  Collection<int, CategorizationRule>  $rules
     */
    public function __construct(private Collection $rules)
    {
        $this->rules = $rules->sortBy([['priority', 'asc'], ['id', 'asc']])->values();
    }

    /**
     * Active rules whose target account is still active.
     */
    public static function forClient(Client $client): self
    {
        return new self(
            CategorizationRule::forClient($client)
                ->where('is_active', true)
                ->whereRelation('account', 'is_active', true)
                ->with('account')
                ->get(),
        );
    }

    public function firstMatch(Transaction $transaction): ?CategorizationRule
    {
        return $this->rules->first(fn (CategorizationRule $rule) => $rule->matches($transaction));
    }

    public function isEmpty(): bool
    {
        return $this->rules->isEmpty();
    }
}
