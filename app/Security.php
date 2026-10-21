<?php
declare(strict_types=1);
namespace App;

final class Security
{
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (Config::get('app.trust_proxy', false)) {
            $cand = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
            if ($cand !== '' && filter_var($cand, FILTER_VALIDATE_IP)) { $ip = $cand; }
        }
        return $ip;
    }

    public static function ipBin(): ?string
    {
        $b = @inet_pton(self::ip());
        return $b === false ? null : $b;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
        return $_SESSION['csrf'];
    }

    /** حماية الطلبات المُغيِّرة: Origin + Content-Type JSON + CSRF token. */
    public static function guardWrite(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host = strtolower((string)strtok($_SERVER['HTTP_HOST'] ?? '', ':'));
        $allowed = array_map('strtolower', (array)Config::get('app.allowed_hosts', []));
        $oh = strtolower((string)parse_url($origin, PHP_URL_HOST));
        if ($origin === '' || $oh === '' || ($oh !== $host && !in_array($oh, $allowed, true))) {
            throw new ApiException('origin', 'طلب مرفوض.', 403);
        }
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($ct, 'application/json') !== 0) { throw new ApiException('content_type', 'طلب مرفوض.', 415); }
        $tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($tok === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $tok)) {
            throw new ApiException('csrf', 'انتهت صلاحية الجلسة، حدّث الصفحة.', 403);
        }
    }

    /* ---- كلمات المرور: SHA-256 مسبق (يتفادى حد 72 بايت في bcrypt) ثم Argon2id أو bcrypt ---- */
    private static function pre(string $pw): string { return base64_encode(hash('sha256', $pw, true)); }

    private static function algo(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return [PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]];
        }
        return [PASSWORD_BCRYPT, ['cost' => 12]];
    }

    public static function hashPassword(string $pw): string
    {
        [$a, $o] = self::algo();
        return password_hash(self::pre($pw), $a, $o);
    }

    public static function verifyPassword(string $pw, string $hash): bool
    {
        return password_verify(self::pre($pw), $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        [$a, $o] = self::algo();
        return password_needs_rehash($hash, $a, $o);
    }
}
