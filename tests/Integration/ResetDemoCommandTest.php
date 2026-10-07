<?php

use App\Models\Client;
use Illuminate\Support\Facades\Storage;

test('demo:reset rebuilds the demo and removes old uploads', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/99/old.csv', 'stale');

    $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

    Storage::disk('local')->assertMissing('imports/99/old.csv');
    expect(Client::count())->toBe(2);
});

test('demo:reset refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:reset', ['--force' => true])->assertFailed();
});
