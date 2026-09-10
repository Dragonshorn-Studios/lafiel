<?php

test('forwarded client IPs are ignored when no proxy is trusted', function () {
    config(['trustedproxy.proxies' => null]);

    $this->get('/', ['X-Forwarded-For' => '203.0.113.7']);

    expect(request()->ip())->toBe('127.0.0.1');
});

test('forwarded client IPs are honored when the caller is a trusted proxy', function () {
    config(['trustedproxy.proxies' => '*']);

    $this->get('/', ['X-Forwarded-For' => '203.0.113.7']);

    expect(request()->ip())->toBe('203.0.113.7');
});
