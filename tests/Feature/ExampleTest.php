<?php

use App\Models\User;

test('guests visiting the root are sent to setup while no administrator exists', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('setup'));
});

test('guests visiting the dashboard alias are sent to the overview', function () {
    User::factory()->create();

    $response = $this->get(route('home'));

    $response->assertRedirect(route('overview'));
});
