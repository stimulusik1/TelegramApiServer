<?php

namespace TelegramApiServer;

/** Recovery uses the existing server credential; it never accepts secrets in a URL. */
final class SessionLoginPolicy
{
    public static function authenticate(string $header, array $passwords): ?string
    {
        if (!preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/iD', $header, $match)) {
            return null;
        }
        $decoded = base64_decode($match[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }
        [$user, $password] = explode(':', $decoded, 2);
        if (!isset($passwords[$user]) || !is_string($passwords[$user]) || $passwords[$user] === '') {
            return null;
        }
        return hash_equals($passwords[$user], $password) ? $user : null;
    }

    public static function issueToken(string $user, string $password, int $now): string
    {
        $payload = ($now + 900) . '.' . bin2hex(random_bytes(24));
        return $payload . '.' . hash_hmac('sha256', $user . '|' . $payload, $password);
    }

    public static function verifyToken(string $token, string $user, string $password, int $now): bool
    {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{48})\.([a-f0-9]{64})$/D', $token, $m)) {
            return false;
        }
        if ((int)$m[1] < $now || (int)$m[1] > $now + 900) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $user . '|' . $m[1] . '.' . $m[2], $password), $m[3]);
    }

    public static function validOrigin(string $origin, string $host): bool
    {
        $parts = parse_url($origin);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === strtolower($host)
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && (!isset($parts['path']) || $parts['path'] === '')
            && (!isset($parts['port']) || $parts['port'] === 443);
    }

    public static function persistentDirectory(string $mount, string $directory): bool
    {
        $mount = $mount === '' ? false : realpath($mount);
        $directory = realpath($directory);
        return $mount !== false && $directory !== false && $mount === $directory && is_writable($directory);
    }

    public static function safeError(\Throwable $error): string
    {
        $message = $error->getMessage();
        foreach (['PHONE_CODE_INVALID', 'PHONE_CODE_EXPIRED', 'PHONE_NUMBER_INVALID', 'PHONE_NUMBER_BANNED',
            'PASSWORD_HASH_INVALID', 'AUTH_KEY_UNREGISTERED', 'SESSION_PASSWORD_NEEDED'] as $safe) {
            if (str_contains($message, $safe)) {
                return $safe;
            }
        }
        return preg_match('/FLOOD_WAIT_\d+/', $message, $m) ? $m[0] : 'LOGIN_FAILED';
    }
}
