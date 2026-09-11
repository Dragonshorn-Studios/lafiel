<?php

use App\Domain\Support\Redaction\Redactor;

it('redacts long secret values embedded in a message', function () {
    $redactor = new Redactor([
        'application_key' => 'hk-0123456789abcdef',
        'application_secret' => 's3cr3t-value-9999',
    ]);

    expect($redactor->message('request failed for key hk-0123456789abcdef at endpoint'))->toBe(
        'request failed for key [redacted] at endpoint',
    );
});

it('redacts short values only when the key names a secret', function () {
    $redactor = new Redactor([
        'consumer_key' => 'np-abc',
        'region' => 'eu',
    ]);

    expect($redactor->message('consumer np-abc rejected in eu'))->toBe(
        'consumer [redacted] rejected in eu',
    );
});

it('redacts every occurrence and all secrets in one message', function () {
    $redactor = new Redactor([
        'application_secret' => 'aaaaaaaa1111',
        'consumer_key' => 'ck-9',
    ]);

    expect($redactor->message('aaaaaaaa1111 then ck-9 then aaaaaaaa1111'))->toBe(
        '[redacted] then [redacted] then [redacted]',
    );
});

it('redacts recursively through arrays', function () {
    $redactor = new Redactor([
        'secret' => 'topsecretvalue',
    ]);

    $redacted = $redactor->array([
        'phase' => 'inventory',
        'warnings' => ['got topsecretvalue back', 'all good'],
        'nested' => ['deep' => 'has topsecretvalue inside'],
    ]);

    expect($redacted)->toBe([
        'phase' => 'inventory',
        'warnings' => ['got [redacted] back', 'all good'],
        'nested' => ['deep' => 'has [redacted] inside'],
    ]);
});

it('leaves non-secret content untouched', function () {
    $redactor = new Redactor([]);

    expect($redactor->message('nothing to hide'))->toBe('nothing to hide')
        ->and($redactor->array(['a' => 1, 'b' => ['c' => 'plain text']]))->toBe(['a' => 1, 'b' => ['c' => 'plain text']]);
});

it('ignores empty and non-scalar payload values', function () {
    $redactor = new Redactor([
        'application_secret' => '',
        'options' => ['debug' => true],
    ]);

    expect($redactor->message('secret is empty'))->toBe('secret is empty');
});

it('redacts the longest secret first when one contains another', function () {
    $redactor = new Redactor([
        'password' => 'secretvalue',
        'api_key' => 'secret',
    ]);

    expect($redactor->message('token secretvalue with secret inside'))->toBe(
        'token [redacted] with [redacted] inside',
    );
});

it('redacts numeric secrets without touching unrelated scalars', function () {
    $redactor = new Redactor([
        'pin' => 12345678,
    ]);

    expect($redactor->array(['code' => 12345678, 'count' => 5, 'flag' => true]))->toBe([
        'code' => '[redacted]',
        'count' => 5,
        'flag' => true,
    ]);
});
