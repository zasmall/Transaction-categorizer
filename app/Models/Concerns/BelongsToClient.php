<?php

namespace App\Models\Concerns;

use App\Models\Client;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models owned by a single client. Queries must be scoped with forClient().
 *
 * @property int $client_id
 * @property-read Client $client
 */
trait BelongsToClient
{
    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forClient(Builder $query, Client $client): void
    {
        $query->where($this->qualifyColumn('client_id'), $client->getKey());
    }
}
