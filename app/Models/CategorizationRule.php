<?php

namespace App\Models;

use App\Enums\RuleDirection;
use App\Enums\RuleMatchField;
use App\Enums\RuleOperator;
use App\Enums\RuleSource;
use App\Models\Concerns\BelongsToClient;
use App\Support\Money;
use Database\Factories\CategorizationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "When the payee contains 'blue bottle', put it in Meals."
 *
 * @property int $id
 * @property string $name
 * @property int $priority Lower runs first.
 * @property RuleMatchField $match_field
 * @property RuleOperator $operator
 * @property string $pattern
 * @property RuleDirection $direction
 * @property int|null $amount_min_cents Inclusive bound on the absolute amount.
 * @property int|null $amount_max_cents Inclusive bound on the absolute amount.
 * @property int $account_id
 * @property RuleSource $source
 * @property int $hits_count
 * @property Carbon|null $last_matched_at
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'priority', 'match_field', 'operator', 'pattern', 'direction',
    'amount_min_cents', 'amount_max_cents', 'account_id', 'source', 'is_active',
])]
class CategorizationRule extends Model
{
    /** @use HasFactory<CategorizationRuleFactory> */
    use BelongsToClient, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'match_field' => RuleMatchField::class,
            'operator' => RuleOperator::class,
            'direction' => RuleDirection::class,
            'amount_min_cents' => 'integer',
            'amount_max_cents' => 'integer',
            'source' => RuleSource::class,
            'hits_count' => 'integer',
            'last_matched_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The ledger account matching transactions are categorized to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function matches(Transaction $transaction): bool
    {
        if (! $this->direction->allows($transaction->amount_cents)) {
            return false;
        }

        $amount = abs($transaction->amount_cents);
        if ($this->amount_min_cents !== null && $amount < $this->amount_min_cents) {
            return false;
        }
        if ($this->amount_max_cents !== null && $amount > $this->amount_max_cents) {
            return false;
        }

        $subject = match ($this->match_field) {
            RuleMatchField::Payee => $transaction->payee_normalized,
            RuleMatchField::Description => $transaction->description_raw,
            RuleMatchField::Memo => $transaction->memo,
        };

        return $subject !== null && $this->matchesText($subject);
    }

    /**
     * Case-insensitive text match. An invalid regex simply never matches.
     */
    private function matchesText(string $subject): bool
    {
        if ($this->operator === RuleOperator::Regex) {
            return @preg_match(self::compileRegex($this->pattern), $subject) === 1;
        }

        $subject = mb_strtolower(trim($subject));
        $pattern = mb_strtolower(trim($this->pattern));

        return match ($this->operator) {
            RuleOperator::Contains => str_contains($subject, $pattern),
            RuleOperator::StartsWith => str_starts_with($subject, $pattern),
            RuleOperator::Equals => $subject === $pattern,
        };
    }

    /**
     * Wrap a user-entered pattern in delimiters with case-insensitive, unicode flags.
     */
    public static function compileRegex(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }

    public static function isValidRegex(string $pattern): bool
    {
        return @preg_match(self::compileRegex($pattern), '') !== false;
    }

    /**
     * A readable summary, e.g. "Payee contains "blue bottle", money out, $10.00–$50.00".
     */
    public function describe(): string
    {
        $parts = [sprintf('%s %s "%s"', $this->match_field->label(), $this->operator->label(), $this->pattern)];

        if ($this->direction !== RuleDirection::Any) {
            $parts[] = $this->direction === RuleDirection::Inflow ? 'money in' : 'money out';
        }

        $parts[] = match (true) {
            $this->amount_min_cents !== null && $this->amount_max_cents !== null => Money::format($this->amount_min_cents).'–'.Money::format($this->amount_max_cents),
            $this->amount_min_cents !== null => 'at least '.Money::format($this->amount_min_cents),
            $this->amount_max_cents !== null => 'up to '.Money::format($this->amount_max_cents),
            default => null,
        };

        return implode(', ', array_filter($parts));
    }
}
