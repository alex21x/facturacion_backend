<?php

namespace App\Support;

class ApiToken
{
    public static function makeAccessToken(array $claims, int $ttlMinutes): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + ($ttlMinutes * 60),
        ]);

        $encodedPayload = self::base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', $encodedPayload, self::signingKey(), true);

        return $encodedPayload . '.' . self::base64UrlEncode($signature);
    }

    public static function parseAccessToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        $encodedPayload = $parts[0];
        $encodedSignature = $parts[1];

        $rawSignature = self::base64UrlDecode($encodedSignature);
        if ($rawSignature === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $encodedPayload, self::signingKey(), true);
        if (!hash_equals($expected, $rawSignature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || !isset($payload['exp'])) {
            return null;
        }

        if ((int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    public static function makeRefreshToken(): string
    {
        return bin2hex(random_bytes(48));
    }

    public static function hashRefreshToken(string $refreshToken, string $deviceId): string
    {
        $deviceHash = sha1(trim($deviceId));

        return $deviceHash . '.' . hash('sha256', $refreshToken);
    }

    public static function deviceHash(string $deviceId): string
    {
        return sha1(trim($deviceId));
    }

    private static function signingKey(): string
    {
        $appKey = (string) env('APP_KEY', 'fallback-dev-key');

        if (strpos($appKey, 'base64:') === 0) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $appKey;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
