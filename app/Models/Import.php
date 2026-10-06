<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded statement file and the progress of its trip through the pipeline.
 *
 * @property int $id
 * @property int $bank_account_id
 * @property int $import_profile_id
 * @property int|null $user_id
 * @property string $original_filename
 * @property string $stored_path
 * @property string $file_hash SHA-256 of the uploaded file.
 * @property ImportStatus $status
 * @property int $total_rows
 * @property int $imported_rows
 * @property int $duplicate_rows
 * @property int $failed_rows
 * @property int $categorized_rows Transactions categorized by rules during this import.
 * @property int $ai_suggested_rows Transactions the AI suggested an account for.
 * @property string|null $ai_model
 * @property int $ai_input_tokens
 * @property int $ai_output_tokens
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'bank_account_id', 'import_profile_id', 'user_id', 'original_filename', 'stored_path', 'file_hash',
    'status', 'total_rows', 'imported_rows', 'duplicate_rows', 'failed_rows', 'categorized_rows', 'ai_suggested_rows', 'ai_model', 'ai_input_tokens', 'ai_output_tokens', 'error', 'started_at', 'finished_at',
])]
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use BelongsToClient, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'imported_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'failed_rows' => 'integer',
            'categorized_rows' => 'integer',
            'ai_suggested_rows' => 'integer',
            'ai_input_tokens' => 'integer',
            'ai_output_tokens' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * The profile used to read this file, kept so the import can be reprocessed exactly.
     *
     * @return BelongsTo<ImportProfile, $this>
     */
    public function importProfile(): BelongsTo
    {
        return $this->belongsTo(ImportProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function markStatus(ImportStatus $status): void
    {
        $this->update(['status' => $status]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => ImportStatus::Failed,
            'error' => $error,
            'finished_at' => now(),
        ]);
    }

    /**
     * Horizon tags so the dashboard can filter to a single import or client.
     *
     * @return list<string>
     */
    public function queueTags(): array
    {
        return ['import:'.$this->id, 'client:'.$this->client_id];
    }
}
