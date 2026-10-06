<?php

namespace App\Enums;

enum ClientRole: string
{
    case Owner = 'owner';
    case Bookkeeper = 'bookkeeper';

    /**
     * Whether this role can change client settings, the chart of accounts and bank accounts.
     */
    public function canManageClient(): bool
    {
        return $this === self::Owner;
    }
}
