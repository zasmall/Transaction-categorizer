<?php

use App\Enums\ClientRole;
use App\Models\Client;
use App\Models\User;

test('owners can view and manage their client', function () {
    $owner = User::factory()->create();
    $client = Client::factory()->create();
    $client->users()->attach($owner, ['role' => ClientRole::Owner]);

    expect($owner->can('view', $client))->toBeTrue()
        ->and($owner->can('update', $client))->toBeTrue()
        ->and($owner->can('delete', $client))->toBeTrue();
});

test('bookkeepers can view but not manage a client', function () {
    $bookkeeper = User::factory()->create();
    $client = Client::factory()->create();
    $client->users()->attach($bookkeeper, ['role' => ClientRole::Bookkeeper]);

    expect($bookkeeper->can('view', $client))->toBeTrue()
        ->and($bookkeeper->can('update', $client))->toBeFalse()
        ->and($bookkeeper->can('delete', $client))->toBeFalse();
});

test('non-members cannot access a client', function () {
    $outsider = User::factory()->create();
    $client = Client::factory()->create();

    expect($outsider->can('view', $client))->toBeFalse()
        ->and($outsider->can('update', $client))->toBeFalse();
});
