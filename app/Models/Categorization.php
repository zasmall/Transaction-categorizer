<?php

namespace App\Models;

use App\Enums\CategorizationMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One categorization decision. The table is append-only: it's the audit trail that
 * answers "why is this transaction in Meals?".
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $account_id
 * @property CategorizationMethod $method
 * @property int|null $rule_id
 * @property string|null $rule_name
 * @property int|null $confidence 0–100, AI only.
 * @property string|null $ai_reason
 * @property string|null $model
 * @property int|null $user_id
 * @property bool $is_current
 * @property Carbon|null $created_at
 */
#[Fillable([
    'transaction_id', 'account_id', 'method', 'rule_id', 'rule_name', 'confidence',
    'ai_reason', 'model', 'user_id', 'is_current',
])]
class Categorization extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => CategorizationMethod::class,
            'confidence' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<CategorizationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CategorizationRule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A short explanation such as "Rule: Coffee shops" or "Manual: Demo Bookkeeper".
     */
    public function explanation(): string
    {
        return match ($this->method) {
            CategorizationMethod::Rule => 'Rule: '.($this->rule_name ?? 'deleted rule'),
            CategorizationMethod::Manual => 'Manual: '.($this->user->name ?? 'unknown user'),
            CategorizationMethod::Ai => sprintf('AI suggestion (%d%% confident)', $this->confidence ?? 0),
        };
    }
}
