<?php

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the lafiel roundel across the app shell', function () {
    $response = $this->get(route('overview'));

    $response->assertOk()
        ->assertSee('/favicon.svg?v=2', false)
        // The retired hand-drawn navigation star must not resurface.
        ->assertDontSee('M12 2c.6 4.9', false);
});

it('renders the roundel on the login page', function () {
    auth()->logout();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('/favicon.svg?v=2', false);
});
