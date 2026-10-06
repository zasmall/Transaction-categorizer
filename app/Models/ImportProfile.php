<?php

namespace App\Models;

use App\Enums\AmountConvention;
use Database\Factories\ImportProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Describes how to read one bank's CSV layout. A null client_id is a system default.
 *
 * @property int $id
 * @property int|null $client_id
 * @property string $name
 * @property string $parser_key
 * @property array<string, string> $column_map Normalized field => CSV header.
 * @property string $date_format PHP date format, e.g. "m/d/Y".
 * @property AmountConvention $amount_convention
 * @property string $delimiter
 * @property bool $has_header
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'parser_key', 'column_map', 'date_format', 'amount_convention', 'delimiter', 'has_header'])]
class ImportProfile extends Model
{
    /** @use HasFactory<ImportProfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'amount_convention' => AmountConvention::class,
            'has_header' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isSystemDefault(): bool
    {
        return $this->client_id === null;
    }

    /**
     * System defaults plus the given client's own profiles.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function availableTo(Builder $query, Client $client): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('client_id')
            ->orWhere('client_id', $client->getKey()));
    }
}
