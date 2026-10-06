<?php

namespace App\Models;

use App\Enums\ClientRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $client_id
 * @property int $user_id
 * @property ClientRole $role
 */
class ClientMembership extends Pivot
{
    protected $table = 'client_user';

    public $incrementing = true;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ClientRole::class,
        ];
    }
}
