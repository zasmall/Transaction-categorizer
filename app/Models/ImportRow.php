<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use App\Imports\Normalizing\NormalizedTransaction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A staged statement row. Keeps the original cells for auditing and reprocessing.
 *
 * @property int $id
 * @property int $import_id
 * @property int $row_number Line number in the source file (1-based, header included).
 * @property array<string, string> $raw
 * @property array{posted_on: string, amount_cents: int, description_raw: string, payee_normalized: string, memo: string|null}|null $normalized
 * @property ImportRowStatus $status
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['import_id', 'row_number', 'raw', 'normalized', 'status', 'error'])]
class ImportRow extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'normalized' => 'array',
            'status' => ImportRowStatus::class,
            'row_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * @return HasOne<Transaction, $this>
     */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function normalizedTransaction(): NormalizedTransaction
    {
        if ($this->normalized === null) {
            throw new LogicException("Import row {$this->id} has not been normalized.");
        }

        return NormalizedTransaction::fromArray($this->normalized);
    }
}
