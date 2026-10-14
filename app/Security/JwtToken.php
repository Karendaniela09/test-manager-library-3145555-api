<?php

namespace App\Security;

use Illuminate\Support\Str;

class JwtToken
{
    public const TTL_SECONDS = 28800;

    public function issue(string $userId, string $role): string
    {
        $secret = (string) config('services.library.jwt_secret');

        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET debe tener al menos 32 caracteres.');
        }

        $header = $this->encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->encode(json_encode([
            'sub' => $userId,
            'role' => $role,
            'iat' => now()->timestamp,
            'exp' => now()->timestamp + self::TTL_SECONDS,
        ], JSON_THROW_ON_ERROR));
        $signature = $this->encode(hash_hmac('sha256', "$header.$payload", $secret, true));

        return "$header.$payload.$signature";
    }

    public function verify(string $token): ?array
    {
        $secret = (string) config('services.library.jwt_secret');
        $parts = explode('.', $token);

        if (strlen($secret) < 32 || count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = $this->encode(hash_hmac('sha256', "$header.$payload", $secret, true));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $claims = json_decode($this->decode($payload), true);

        if (! is_array($claims)
            || ! isset($claims['sub'], $claims['role'], $claims['exp'])
            || ! is_string($claims['sub'])
            || ! in_array($claims['role'], ['admin', 'librarian'], true)
            || ! is_int($claims['exp'])
            || $claims['exp'] <= now()->timestamp) {
            return null;
        }

        return $claims;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
