<?php

use App\Models\User;

test('guests are redirected away from the horizon dashboard', function () {
    $this->get('/horizon')->assertRedirect(route('login'));
});

test('authenticated users can view the horizon dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get('/horizon')
        ->assertOk();
});
