<?php
declare(strict_types=1);
namespace App;

/**
 * البريد الإلكتروني عبر PHPMailer (SMTP). لو المكتبة أو الإعدادات ناقصة، الإرسال بيتعطل بهدوء ويتسجّل في السجل
 * ولا يكسر أي عملية (التسجيل/الدخول يكملوا عادي).
 */
final class Mailer
{
    private static array $queue = [];
    private static bool $loaded = false;

    private static function lib(): bool
    {
        if (self::$loaded || class_exists('\PHPMailer\PHPMailer\PHPMailer', false)) { return true; }
        $d = APP_ROOT . '/lib/PHPMailer/src/';
        foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $f) {
            if (!is_file($d . $f)) { return false; }
        }
        require_once $d . 'Exception.php';
        require_once $d . 'PHPMailer.php';
        require_once $d . 'SMTP.php';
        return self::$loaded = true;
    }

    public static function enabled(): bool
    {
        return (bool)Config::get('mail.enabled', false) && (string)Config::get('mail.host', '') !== '' && self::lib();
    }

    public static function baseUrl(): string
    {
        $b = rtrim((string)Config::get('app.base_url', ''), '/');
        if ($b !== '') { return $b; }
        $h = (string)strtok($_SERVER['HTTP_HOST'] ?? '', ':');
        $allowed = (array)Config::get('app.allowed_hosts', []);
        if ($allowed && !in_array(strtolower($h), array_map('strtolower', $allowed), true)) { $h = (string)$allowed[0]; }
        return (Security::isHttps() ? 'https://' : 'http://') . $h;
    }

    /** إرسال فوري. يرجع true لو نجح. لا يرمي استثناء. */
    public static function send(string $to, string $toName, string $subject, string $html, ?string $text = null): bool
    {
        if (!self::enabled()) { return false; }
        try {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host = (string)Config::get('mail.host');
            $m->Port = (int)Config::get('mail.port', 587);
            $sec = (string)Config::get('mail.secure', 'tls');
            $m->SMTPSecure = $sec === 'ssl' ? 'ssl' : ($sec === 'none' ? '' : 'tls');
            $m->SMTPAutoTLS = $sec !== 'none';
            $user = (string)Config::get('mail.user', '');
            $m->SMTPAuth = $user !== '';
            if ($user !== '') { $m->Username = $user; $m->Password = (string)Config::get('mail.pass', ''); }
            $m->Timeout = (int)Config::get('mail.timeout', 8);
            $m->CharSet = 'UTF-8';
            $m->Encoding = 'base64';
            $from = (string)Config::get('mail.from', $user);
            $m->setFrom($from, (string)Config::get('mail.from_name', 'Port Said Hub'));
            $m->addAddress($to, str_replace(["\r", "\n"], ' ', $toName));
            $m->Subject = str_replace(["\r", "\n"], ' ', $subject);
            $m->isHTML(true);
            $m->Body = $html;
            $m->AltBody = $text ?? trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
            $m->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[mail] ' . $e->getMessage());
            Audit::event(null, 'mail_failed', 'warning', 'فشل إرسال بريد إلى ' . self::mask($to) . ' بعنوان «' . mb_substr($subject, 0, 80) . '»', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return false;
        }
    }

    /** يُرسَل بعد ما الرد يوصل للمتصفح (Http::ok/fail بتنادي flush) عشان ما نبطّئ الدخول/التسجيل. */
    public static function later(string $to, string $toName, string $subject, string $html): void
    {
        if (self::enabled()) { self::$queue[] = [$to, $toName, $subject, $html]; }
    }

    public static function flush(): void
    {
        if (!self::$queue) { return; }
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        $q = self::$queue;
        self::$queue = [];
        foreach (array_slice($q, 0, 5) as $m) { self::send($m[0], $m[1], $m[2], $m[3]); }
    }

    public static function mask(string $email): string
    {
        $p = explode('@', $email, 2);
        return (mb_substr($p[0], 0, 2)) . '***' . (isset($p[1]) ? '@' . $p[1] : '');
    }

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** قالب موحّد بنفس ألوان الموقع (ocean/teal/sand). $bodyHtml لازم يكون معقّم من المُنادي. */
    public static function layout(string $title, string $bodyHtml, ?array $button = null): string
    {
        $logo = self::e(self::baseUrl() . '/assets/logo/icon-192.png');
        $btn = $button ? '<p style="text-align:center;margin:26px 0 6px"><a href="' . self::e($button[1]) . '" style="display:inline-block;background:#0a9a92;color:#fff;font-weight:800;text-decoration:none;padding:13px 30px;border-radius:16px;font-size:16px">' . self::e($button[0]) . '</a></p><p style="font-size:12px;color:#7a8ba3;text-align:center;direction:ltr;word-break:break-all">' . self::e($button[1]) . '</p>' : '';
        return '<!DOCTYPE html><html lang="ar" dir="rtl"><body style="margin:0;background:#fffaf0;font-family:Tahoma,Arial,sans-serif;color:#0b2a5b">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffaf0;padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #ffe3a8">'
            . '<tr><td style="background:#0f4fb8;background-image:linear-gradient(135deg,#082a6e,#0f4fb8 60%,#0a6f8a);padding:26px;text-align:center;color:#fff">'
            . '<img src="' . $logo . '" width="64" height="64" alt="" style="border-radius:50%;background:#f8f4ea;border:3px solid rgba(255,255,255,.7)"><div style="font-size:20px;font-weight:800;margin-top:10px" dir="ltr">Port Said Hub</div><div style="font-size:14px;opacity:.85">دليلك في بورسعيد</div></td></tr>'
            . '<tr><td style="padding:28px 26px;font-size:16px;line-height:1.9"><h2 style="margin:0 0 12px;font-size:20px;color:#0b2a5b">' . self::e($title) . '</h2>' . $bodyHtml . $btn . '</td></tr>'
            . '<tr><td style="background:#fff1d6;padding:14px;text-align:center;font-size:12px;color:#7a5a12">رسالة تلقائية من Port Said Hub · لو مش انت اللي طلبتها تجاهلها.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public static function verifyMail(string $to, string $name, string $token): void
    {
        $link = self::baseUrl() . '/verify.html?t=' . $token;
        self::later($to, $name, 'وثّق بريدك الإلكتروني | Port Said Hub',
            self::layout('أهلًا ' . $name, '<p>اضغط الزر لتوثيق بريدك الإلكتروني. الرابط صالح لمدة 24 ساعة.</p>', ['توثيق البريد', $link]));
    }

    /** إرسال فوري (للزر اليدوي) عشان نعرف لو فشل بدل ما نقول للمستخدم إنه اتبعت وهو لأ. */
    public static function verifyMailNow(string $to, string $name, string $token): bool
    {
        $link = self::baseUrl() . '/verify.html?t=' . $token;
        return self::send($to, $name, 'وثّق بريدك الإلكتروني | Port Said Hub',
            self::layout('أهلًا ' . $name, '<p>اضغط الزر لتوثيق بريدك الإلكتروني. الرابط صالح لمدة 24 ساعة.</p>', ['توثيق البريد', $link]));
    }

    public static function notice(string $to, string $name, string $title, string $line): void
    {
        self::later($to, $name, $title . ' | Port Said Hub', self::layout($title, '<p>' . nl2br(self::e($line)) . '</p>'));
    }

    public static function loginNotice(string $to, string $name, string $ip, string $ua): void
    {
        $when = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Cairo')))->format('d/m/Y h:i A');
        $body = '<p>تم تسجيل الدخول إلى حسابك.</p><table style="width:100%;font-size:15px;background:#fffaf0;border-radius:12px;padding:10px"><tr><td>الوقت (القاهرة):</td><td dir="ltr" style="text-align:right">' . self::e($when) . '</td></tr><tr><td>عنوان الشبكة:</td><td dir="ltr" style="text-align:right">' . self::e($ip) . '</td></tr><tr><td>الجهاز:</td><td dir="ltr" style="text-align:right">' . self::e(mb_substr($ua, 0, 90)) . '</td></tr></table><p>لو مش انت، غيّر كلمة المرور فورًا.</p>';
        self::later($to, $name, 'تسجيل دخول جديد إلى حسابك | Port Said Hub', self::layout('تنبيه تسجيل دخول', $body));
    }

    /** رسالة من المالك/الأدمن للمستخدم (النص معقّم ومحوّل لأسطر). يرجع نجاح الإرسال. */
    public static function staffMessage(string $to, string $name, string $subject, string $message, string $fromWho): bool
    {
        $body = '<p>' . nl2br(self::e($message)) . '</p><p style="font-size:13px;color:#5b6f8f">— ' . self::e($fromWho) . ' · فريق Port Said Hub</p>';
        return self::send($to, $name, $subject, self::layout($subject, $body));
    }
}
