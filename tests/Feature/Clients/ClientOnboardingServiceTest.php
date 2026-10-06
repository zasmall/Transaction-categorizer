<?php

use App\Enums\ClientRole;
use App\Models\User;
use App\Services\ClientOnboardingService;
use App\Support\DefaultChartOfAccounts;

test('it creates a client owned by the user', function () {
    $owner = User::factory()->create();

    $client = app(ClientOnboardingService::class)->create($owner, 'Acme Plumbing', fiscalYearStart: 4);

    expect($client->name)->toBe('Acme Plumbing')
        ->and($client->slug)->toBe('acme-plumbing')
        ->and($client->fiscal_year_start)->toBe(4)
        ->and($client->roleFor($owner))->toBe(ClientRole::Owner);
});

test('it seeds the default chart of accounts', function () {
    $client = app(ClientOnboardingService::class)->create(User::factory()->create(), 'Acme Plumbing');

    expect($client->accounts()->count())->toBe(count(DefaultChartOfAccounts::accounts()))
        ->and($client->accounts()->where('code', '1000')->value('name'))->toBe('Business Checking');
});

test('it gives clients with the same name unique slugs', function () {
    $service = app(ClientOnboardingService::class);
    $owner = User::factory()->create();

    $service->create($owner, 'Acme Plumbing');
    $second = $service->create($owner, 'Acme Plumbing');
    $third = $service->create($owner, 'Acme Plumbing');

    expect($second->slug)->toBe('acme-plumbing-2')
        ->and($third->slug)->toBe('acme-plumbing-3');
});

test('the default chart of accounts has unique codes', function () {
    $codes = array_column(DefaultChartOfAccounts::accounts(), 'code');

    expect($codes)->toHaveCount(count(array_unique($codes)));
});
