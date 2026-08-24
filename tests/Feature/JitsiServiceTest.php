<?php

use App\Services\JitsiService;
use Illuminate\Support\Facades\Log;

/*
| These tests sign with a throwaway RSA keypair generated here, never with the real
| configured credentials. No assertion is ever handed a credential value, so a failing
| assertion cannot leak one.
|
| They live under Feature rather than Unit because JitsiService reads Laravel config,
| which needs the container — tests/Pest.php binds TestCase only to the Feature suite.
*/

function jitsiTestKeypair(): array
{
    static $keypair = null;

    if ($keypair !== null) {
        return $keypair;
    }

    // Windows/XAMPP ships no OPENSSL_CONF by default, and keygen fails without one.
    $options = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    foreach ([getenv('OPENSSL_CONF'), 'C:\xampp\php\extras\ssl\openssl.cnf', 'C:\xampp\apache\conf\openssl.cnf'] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
            $options['config'] = $candidate;
            break;
        }
    }

    $resource = openssl_pkey_new($options);

    if ($resource === false) {
        test()->markTestSkipped('OpenSSL cannot generate an RSA keypair in this environment.');
    }

    openssl_pkey_export($resource, $privateKey, null, $options);
    $publicKey = openssl_pkey_get_details($resource)['key'];

    return $keypair = [$privateKey, $publicKey];
}

function jitsiDecode(string $token): array
{
    [$header, $payload, $signature] = explode('.', $token);

    $decode = fn (string $segment) => base64_decode(strtr($segment, '-_', '+/'));

    return [
        'header' => json_decode($decode($header), true),
        'payload' => json_decode($decode($payload), true),
        'signature' => $decode($signature),
        'signing_input' => $header.'.'.$payload,
    ];
}

beforeEach(function () {
    [$privateKey] = jitsiTestKeypair();

    config()->set('services.jitsi', [
        'domain' => '8x8.vc',
        'app_id' => 'vpaas-magic-cookie-testtenant0000000000000000000',
        'api_key_id' => 'vpaas-magic-cookie-testtenant0000000000000000000/abc123',
        'private_key' => $privateKey,
        'jwt_ttl' => 7200,
    ]);

    $this->jitsi = new JitsiService;
});

it('signs the token with RS256 and declares it in the header', function () {
    $token = jitsiDecode($this->jitsi->issueToken('room1234', 'Dr Reyes', true));

    expect($token['header']['alg'])->toBe('RS256')
        ->and($token['header']['typ'])->toBe('JWT');
});

it('produces a signature that verifies against the matching public key', function () {
    [, $publicKey] = jitsiTestKeypair();

    $token = jitsiDecode($this->jitsi->issueToken('room1234', 'Dr Reyes', true));

    $verified = openssl_verify(
        $token['signing_input'],
        $token['signature'],
        $publicKey,
        OPENSSL_ALGO_SHA256
    );

    expect($verified)->toBe(1);
});

it('uses the configured api key id verbatim as the kid', function () {
    $token = jitsiDecode($this->jitsi->issueToken('room1234', 'Dr Reyes', true));

    expect($token['header']['kid'])->toBe(config('services.jitsi.api_key_id'))
        ->and(substr_count($token['header']['kid'], '/'))->toBe(1);
});

it('sets aud to jitsi', function () {
    $token = jitsiDecode($this->jitsi->issueToken('room1234', 'Dr Reyes', true));

    expect($token['payload']['aud'])->toBe('jitsi');
});

it('sets iss to chat', function () {
    $token = jitsiDecode($this->jitsi->issueToken('room1234', 'Dr Reyes', true));

    expect($token['payload']['iss'])->toBe('chat');
});

it('sets sub to the configured app id', function () {
    $token = jitsiDecode($this->jitsi->issueToken('room1234', 'Dr Reyes', true));

    expect($token['payload']['sub'])->toBe(config('services.jitsi.app_id'));
});

it('puts the bare room name in the room claim, never the iframe form', function () {
    $room = $this->jitsi->generateRoomName();

    $token = jitsiDecode($this->jitsi->issueToken($room, 'Dr Reyes', true));

    expect($token['payload']['room'])->toBe($room)
        ->and($token['payload']['room'])->not->toContain('/')
        ->and($token['payload']['room'])->not->toBe($this->jitsi->iframeRoomName($room));
});

it('prefixes only the iframe room identifier with the app id', function () {
    $room = $this->jitsi->generateRoomName();

    expect($this->jitsi->iframeRoomName($room))
        ->toBe(config('services.jitsi.app_id').'/'.$room);
});

it('sets exp from the configured ttl and backdates nbf for clock skew', function () {
    $this->travelTo(now()->startOfSecond());
    $issuedAt = now()->getTimestamp();

    $token = jitsiDecode($this->jitsi->issueToken('room1234', 'Dr Reyes', true));

    expect($token['payload']['exp'])->toBe($issuedAt + 7200)
        ->and($token['payload']['nbf'])->toBe($issuedAt - 10)
        ->and($token['payload']['exp'])->toBeGreaterThan($token['payload']['nbf']);
});

it('marks the physician as moderator and the patient as not', function () {
    $asPhysician = jitsiDecode($this->jitsi->issueToken('room1234', 'Dr Reyes', true));
    $asPatient = jitsiDecode($this->jitsi->issueToken('room1234', 'Maya Cruz', false));

    expect($asPhysician['payload']['context']['user']['moderator'])->toBe('true')
        ->and($asPatient['payload']['context']['user']['moderator'])->toBe('false');
});

it('carries only a display name and moderator flag in the user context', function () {
    $token = jitsiDecode($this->jitsi->issueToken('room1234', 'Maya Cruz', false));

    expect(array_keys($token['payload']['context']))->toBe(['user'])
        ->and(array_keys($token['payload']['context']['user']))->toBe(['name', 'moderator']);
});

it('omits the features block entirely', function () {
    $token = jitsiDecode($this->jitsi->issueToken('room1234', 'Dr Reyes', true));

    expect($token['payload']['context'])->not->toHaveKey('features')
        ->and($token['payload'])->not->toHaveKey('features');
});

it('generates room names that cannot carry identifying information', function () {
    foreach (range(1, 50) as $ignored) {
        expect($this->jitsi->generateRoomName())->toMatch('/^[0-9a-f]{32}$/');
    }
});

it('generates a different room name every time', function () {
    $rooms = collect(range(1, 1000))->map(fn () => $this->jitsi->generateRoomName());

    expect($rooms->unique()->count())->toBe(1000);
});

it('never leaks the private key into the token or any client-facing value', function () {
    [$privateKey] = jitsiTestKeypair();

    $room = $this->jitsi->generateRoomName();
    $clientFacingValues = [
        $this->jitsi->issueToken($room, 'Dr Reyes', true),
        $room,
        $this->jitsi->iframeRoomName($room),
        $this->jitsi->domain(),
    ];

    $keyBody = trim(str_replace(
        ['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\n", "\r"],
        '',
        $privateKey
    ));

    foreach ($clientFacingValues as $value) {
        expect(str_contains($value, $keyBody))->toBeFalse()
            ->and(str_contains($value, 'BEGIN PRIVATE KEY'))->toBeFalse()
            ->and(str_contains($value, substr($keyBody, 0, 64)))->toBeFalse();
    }
});

it('writes nothing to the log while issuing a token', function () {
    Log::spy();

    $this->jitsi->issueToken($this->jitsi->generateRoomName(), 'Dr Reyes', true);

    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('fails loudly without naming the value when a credential is missing', function () {
    config()->set('services.jitsi.private_key', '');

    expect(fn () => $this->jitsi->issueToken('room1234', 'Dr Reyes', true))
        ->toThrow(RuntimeException::class, 'Jitsi configuration [services.jitsi.private_key] is not set.');
});

it('rejects an unparseable private key without echoing it', function () {
    config()->set('services.jitsi.private_key', 'not-a-pem-block');

    expect(fn () => $this->jitsi->issueToken('room1234', 'Dr Reyes', true))
        ->toThrow(RuntimeException::class, 'The configured Jitsi private key could not be parsed.');
});
