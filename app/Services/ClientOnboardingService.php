<?php

namespace App\Services;

use App\Enums\ClientRole;
use App\Models\Client;
use App\Models\User;
use App\Support\DefaultChartOfAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientOnboardingService
{
    /**
     * Create a client owned by the given user, with a starter chart of accounts.
     */
    public function create(User $owner, string $name, int $fiscalYearStart = 1): Client
    {
        return DB::transaction(function () use ($owner, $name, $fiscalYearStart) {
            $client = Client::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'fiscal_year_start' => $fiscalYearStart,
            ]);

            $client->users()->attach($owner, ['role' => ClientRole::Owner]);

            $client->accounts()->createMany(DefaultChartOfAccounts::accounts());

            return $client;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Client::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
