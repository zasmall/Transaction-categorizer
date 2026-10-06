<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    /**
     * Any member can view the client and work on its transactions.
     */
    public function view(User $user, Client $client): bool
    {
        return $client->roleFor($user) !== null;
    }

    /**
     * Only owners can change client settings, the chart of accounts and bank accounts.
     */
    public function update(User $user, Client $client): bool
    {
        return $client->roleFor($user)?->canManageClient() ?? false;
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }
}
