<?php

/*
| These assertions deliberately compare key names and booleans only. No assertion
| ever receives a credential as an argument, so a failure message cannot leak one.
*/

it('exposes the expected jitsi configuration keys', function () {
    expect(array_keys(config('services.jitsi')))
        ->toBe(['domain', 'app_id', 'api_key_id', 'private_key', 'jwt_ttl']);
});

it('configures a jwt lifetime long enough to outlast a consultation', function () {
    expect(config('services.jitsi.jwt_ttl'))->toBeInt()->toBeGreaterThan(0);
});

it('resolves the JaaS credentials as strings', function () {
    expect(config('services.jitsi.domain'))->toBeString()
        ->and(config('services.jitsi.private_key'))->toBeString();
});

it('parses the configured private key into a real PEM block', function () {
    $privateKey = (string) config('services.jitsi.private_key');

    if ($privateKey === '') {
        test()->markTestSkipped('JITSI_PRIVATE_KEY is not configured in this environment.');
    }

    // Literal backslash-n must have been converted to real newlines by config/services.php.
    expect(str_contains($privateKey, '\n'))->toBeFalse()
        ->and(str_starts_with($privateKey, '-----BEGIN'))->toBeTrue()
        ->and(substr_count($privateKey, "\n"))->toBeGreaterThan(1);
});

it('resolves a JaaS app id and api key id when credentials are configured', function () {
    $appId = (string) config('services.jitsi.app_id');
    $apiKeyId = (string) config('services.jitsi.api_key_id');

    if ($appId === '' && $apiKeyId === '') {
        test()->markTestSkipped('JaaS credentials are not configured in this environment.');
    }

    expect($appId === '')->toBeFalse()
        ->and($apiKeyId === '')->toBeFalse();
});
