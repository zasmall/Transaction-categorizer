<?php

namespace App\Models;

use App\Enums\CategorizationStatus;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $bank_account_id
 * @property int|null $import_id
 * @property int|null $import_row_id
 * @property Carbon $posted_on
 * @property int $amount_cents Negative is money out, positive is money in.
 * @property string $description_raw
 * @property string $payee_normalized
 * @property string|null $memo
 * @property string $fingerprint
 * @property int|null $account_id
 * @property CategorizationStatus $categorization_status
 * @property Carbon|null $exported_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'bank_account_id', 'import_id', 'import_row_id', 'posted_on', 'amount_cents', 'description_raw',
    'payee_normalized', 'memo', 'fingerprint', 'account_id', 'categorization_status', 'exported_at',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToClient, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_on' => 'date',
            'amount_cents' => 'integer',
            'categorization_status' => CategorizationStatus::class,
            'exported_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * The ledger account this transaction is categorized to, if any.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Every categorization decision for this transaction, oldest first.
     *
     * @return HasMany<Categorization, $this>
     */
    public function categorizations(): HasMany
    {
        return $this->hasMany(Categorization::class)->oldest('id');
    }

    /**
     * @return HasOne<Categorization, $this>
     */
    public function currentCategorization(): HasOne
    {
        return $this->hasOne(Categorization::class)->where('is_current', true);
    }
}
