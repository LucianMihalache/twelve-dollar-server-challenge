<?php

/*
| The bearer token of a request: an HS256 JWT signed with JWT_SECRET. Verified by hand on every request (signature
| and expiry), nothing is remembered between requests (rule 5).
*/
final class Jwt
{
    public const MISSING = 'missing bearer token';
    public const INVALID = 'invalid or expired token';
    public const PAYLOAD = 'invalid token payload';

    /** {"alg":"HS256","typ":"JWT"} as every common library writes it: recognised without decoding it. */
    private const USUAL_HEADER = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';

    private static ?string $secret = null;

    /**
     * The user a request's Authorization header names.
     *
     * @return array{0: int, 1: string}|string [user id, username], or the error message to answer 401 with
     */
    public static function user(?string $authorization): array|string
    {
        if ($authorization === null || ! str_starts_with($authorization, 'Bearer ')) {
            return self::MISSING;
        }
        $parts = explode('.', substr($authorization, 7));
        if (count($parts) !== 3) {
            return self::INVALID;
        }
        [$header, $payload, $signature] = $parts;

        if ($header !== self::USUAL_HEADER) {
            $decoded = json_decode((string) self::decode($header), true);
            if (! is_array($decoded) || ($decoded['alg'] ?? null) !== 'HS256') {
                return self::INVALID;
            }
        }
        $given = self::decode($signature);
        $secret = self::$secret ??= (string) ($_SERVER['JWT_SECRET'] ?? getenv('JWT_SECRET'));
        if ($given === false || ! hash_equals(hash_hmac('sha256', $header . '.' . $payload, $secret, true), $given)) {
            return self::INVALID;
        }
        $claims = json_decode((string) self::decode($payload), true);
        if (! is_array($claims)) {
            return self::INVALID;
        }
        if (isset($claims['exp']) && (! is_numeric($claims['exp']) || $claims['exp'] <= time())) {
            return self::INVALID;
        }
        $sub = $claims['sub'] ?? null;
        $username = $claims['username'] ?? null;
        if (! is_string($sub) || ! is_string($username) || ! preg_match('/^[1-9][0-9]{0,17}$/', $sub)) {
            return self::PAYLOAD;
        }

        return [(int) $sub, $username];
    }

    private static function decode(string $base64url): string|false
    {
        return base64_decode(strtr($base64url, '-_', '+/'), true);
    }
}
