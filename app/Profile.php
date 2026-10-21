<?php
declare(strict_types=1);
namespace App;

/** الملف الشخصي: توثيق البريد (رفع الصورة الشخصية اتشال). */
final class Profile
{
    private static function dir(): string { return dirname(__DIR__) . '/assets/avatars'; }

    /** بيمسح أي صورة شخصية قديمة لما الحساب يتحذف. */
    public static function dropAvatarFile(?string $name): void
    {
        if ($name && preg_match('~^a_[a-f0-9]{24}\.jpg$~', $name)) { @unlink(self::dir() . '/' . $name); }
    }

    /* ---------------- توثيق البريد ---------------- */

    /** ينشئ توكن جديد (يلغي القديم) ويجدول إرسال بريد التوثيق. */
    public static function issueVerification(int $userId, string $email, string $name, bool $now = false): bool
    {
        $tok = bin2hex(random_bytes(32));
        Db::run('DELETE FROM email_tokens WHERE user_id = ?', [$userId]);
        Db::run('INSERT INTO email_tokens (user_id, email, token_hash, expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))', [$userId, $email, hash('sha256', $tok)]);
        if ($now) { return Mailer::verifyMailNow($email, $name, $tok); }
        Mailer::verifyMail($email, $name, $tok);
        return true;
    }

    public static function resend(): void
    {
        $u = Auth::need([], true);
        if (empty($u['email'])) { throw new ApiException('validation', 'مفيش بريد إلكتروني على حسابك.', 422); }
        if (!empty($u['email_verified_at'])) { throw new ApiException('validation', 'بريدك موثّق بالفعل.', 422); }
        if (!Mailer::enabled()) { throw new ApiException('mail_off', 'خدمة البريد غير مفعّلة حاليًا. تواصل مع الإدارة.', 503); }
        RateLimiter::hit('resend:' . $u['id'], 3, 3600);
        if (!self::issueVerification((int)$u['id'], (string)$u['email'], (string)$u['name'], true)) {
            throw new ApiException('mail_failed', 'مقدرناش نبعت الرسالة دلوقتي. جرّب كمان شوية، ولو لسه مش شغالة بلّغ الإدارة.', 502);
        }
        Http::ok(['sent' => true]);
    }

    public static function verify(): void
    {
        RateLimiter::hit('verify:' . Security::ip(), 20, 3600);
        $b = Http::body();
        $t = $b['token'] ?? null;
        if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) { throw new ApiException('invalid_token', 'الرابط غير صحيح أو منتهي.', 422); }
        $r = Db::one('SELECT t.id, t.user_id, t.email, u.name, u.email AS cur FROM email_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > UTC_TIMESTAMP() AND u.deleted_at IS NULL', [hash('sha256', $t)]);
        if (!$r || $r['cur'] !== $r['email']) { throw new ApiException('invalid_token', 'الرابط غير صحيح أو منتهي. اطلب رابط جديد من حسابك.', 422); }
        Db::tx(function () use ($r) {
            Db::run('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?', [$r['user_id']]);
            Db::run('UPDATE email_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$r['id']]);
        });
        Audit::event((int)$r['user_id'], 'email_verify', 'info', 'تم توثيق البريد الإلكتروني للحساب: ' . $r['name']);
        Http::ok(['verified' => true]);
    }
}
