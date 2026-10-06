<?php

namespace App\Models;

use App\Enums\ClientRole;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $fiscal_year_start Month number (1 = January).
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'fiscal_year_start', 'settings'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year_start' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, ClientMembership, 'membership'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(ClientMembership::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * Profiles created for this client only (system defaults are excluded).
     *
     * @return HasMany<ImportProfile, $this>
     */
    public function importProfiles(): HasMany
    {
        return $this->hasMany(ImportProfile::class);
    }

    /**
     * The given user's role on this client, or null if they are not a member.
     */
    public function roleFor(User $user): ?ClientRole
    {
        return ClientMembership::query()
            ->where('client_id', $this->getKey())
            ->where('user_id', $user->getKey())
            ->first()
            ?->role;
    }
}
