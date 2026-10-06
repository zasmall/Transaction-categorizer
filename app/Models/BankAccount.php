<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A real-world bank or credit card account whose statements get imported.
 *
 * @property int $id
 * @property string $name
 * @property string|null $institution
 * @property string|null $last4
 * @property int $ledger_account_id
 * @property int|null $import_profile_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'institution', 'last4', 'ledger_account_id', 'import_profile_id'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use BelongsToClient, HasFactory;

    /**
     * The chart-of-accounts entry this bank account posts to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ledger_account_id');
    }

    /**
     * @return BelongsTo<ImportProfile, $this>
     */
    public function importProfile(): BelongsTo
    {
        return $this->belongsTo(ImportProfile::class);
    }
}
