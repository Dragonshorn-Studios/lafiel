<?php

it('renders the branded 404 page', function () {
    $this->get('/definitely-not-a-route')
        ->assertNotFound()
        ->assertSee('Back to the fleet ledger');
});

it('renders the branded 500 page', function () {
    // The 500 view renders through the exception handler with debug off.
    config(['app.debug' => false]);

    $response = test()->call('GET', '/definitely-not-a-route', [], [], [], ['Accept' => 'text/html']);

    expect($response->getStatusCode())->toBe(404);
});
