<?php

test('guests visiting the root are sent to the dashboard, which requires login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('dashboard'));
});
