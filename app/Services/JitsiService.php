<?php

namespace App\Services;

use RuntimeException;

/**
 * Mints JaaS (8x8) access tokens and generates consultation room names.
 *
 * This service is deliberately stateless: it never touches the database and never
 * decides who is allowed into a room. Authorization happens before a token is
 * requested, and the video session lifecycle lives with the consultation models.
 *
 * Claim structure follows https://developer.8x8.com/jaas/docs/api-keys-jwt
 */
class JitsiService
{
    /** 16 bytes of randomness renders as 32 hex characters (128 bits). */
    private const ROOM_NAME_BYTES = 16;

    /** Matches the 8x8 reference sample: backdate nbf slightly for clock skew. */
    private const NBF_SKEW_SECONDS = 10;

    /**
     * The JaaS host the IFrame API connects to.
     */
    public function domain(): string
    {
        return $this->requiredConfig('domain');
    }

    /**
     * An unpredictable, non-identifying room name.
     *
     * Pure lowercase hex, so it structurally cannot carry a patient name, email,
     * user id, or consultation id. 128 bits of entropy from a CSPRNG makes a
     * collision unreachable in practice, so this never consults the database —
     * the unique index on consultation_video_sessions.room_name stays the final
     * arbiter. Lowercase matters: Jitsi normalises room case in some configurations,
     * which would break the exact-match on the JWT "room" claim.
     */
    public function generateRoomName(): string
    {
        return bin2hex(random_bytes(self::ROOM_NAME_BYTES));
    }

    /**
     * The room identifier the IFrame API expects: "{appId}/{roomName}".
     *
     * Kept distinct from the bare room name on purpose. The JWT "room" claim must
     * carry the bare name; only the client-side room identifier is prefixed.
     */
    public function iframeRoomName(string $roomName): string
    {
        return $this->requiredConfig('app_id').'/'.$roomName;
    }

    /**
     * Issue a short-lived RS256 token granting access to exactly one room.
     *
     * @param  string  $roomName  the bare generated room name, never the prefixed form
     */
    public function issueToken(string $roomName, string $displayName, bool $isModerator): string
    {
        $issuedAt = now()->getTimestamp();

        $header = [
            'alg' => 'RS256',
            'kid' => $this->requiredConfig('api_key_id'),
            'typ' => 'JWT',
        ];

        $payload = [
            'aud' => 'jitsi',
            'iss' => 'chat',
            'sub' => $this->requiredConfig('app_id'),
            'room' => $roomName,
            'nbf' => $issuedAt - self::NBF_SKEW_SECONDS,
            'exp' => $issuedAt + $this->jwtTtl(),
            'context' => [
                'user' => [
                    'name' => $displayName,
                    'moderator' => $isModerator ? 'true' : 'false',
                ],
            ],
        ];

        $signingInput = $this->encodeSegment($header).'.'.$this->encodeSegment($payload);

        return $signingInput.'.'.$this->base64UrlEncode($this->sign($signingInput));
    }

    private function sign(string $signingInput): string
    {
        $privateKey = openssl_pkey_get_private(
            $this->normalizePem($this->requiredConfig('private_key'))
        );

        if ($privateKey === false) {
            // Deliberately says nothing about the key itself.
            throw new RuntimeException('The configured Jitsi private key could not be parsed.');
        }

        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign the Jitsi access token.');
        }

        return $signature;
    }

    /**
     * Rebuild a PEM block from its base64 body, discarding stray whitespace.
     *
     * A key escaped onto a single .env line commonly ends up with a blank line just
     * after the BEGIN marker (an extra "\n"). OpenSSL 3 reads a blank line there as
     * the start of an RFC 7468 PEM headers section and rejects the whole block with a
     * generic "DECODER routines::unsupported", even though the base64 body is intact.
     * Rebuilding the block sidesteps that without altering the key material, so a
     * correct key works regardless of how it was pasted.
     *
     * Input that is not a PEM block is returned untouched so openssl reports it.
     */
    private function normalizePem(string $key): string
    {
        if (! preg_match('/-----BEGIN ([A-Z0-9 ]+)-----(.*?)-----END \1-----/s', $key, $matches)) {
            return $key;
        }

        $body = preg_replace('/\s+/', '', $matches[2]);

        return "-----BEGIN {$matches[1]}-----\n"
            .chunk_split($body, 64, "\n")
            ."-----END {$matches[1]}-----\n";
    }

    private function encodeSegment(array $segment): string
    {
        // Slashes stay unescaped so the "{appId}/{hash}" kid reads normally.
        return $this->base64UrlEncode(
            json_encode($segment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function jwtTtl(): int
    {
        return (int) config('services.jitsi.jwt_ttl');
    }

    /**
     * Read a configured value, failing loudly rather than minting an invalid token.
     * The exception names the config key, never the value behind it.
     */
    private function requiredConfig(string $key): string
    {
        $value = (string) config('services.jitsi.'.$key);

        if ($value === '') {
            throw new RuntimeException("Jitsi configuration [services.jitsi.{$key}] is not set.");
        }

        return $value;
    }
}
