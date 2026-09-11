<?php

use App\Models\User;

test('guests visiting the root are sent to setup while no administrator exists', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('setup'));
});

test('guests visiting the root are sent to the dashboard once installed', function () {
    User::factory()->create();

    $response = $this->get(route('home'));

    $response->assertRedirect(route('dashboard'));
});
