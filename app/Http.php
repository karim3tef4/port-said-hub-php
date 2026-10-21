<?php
declare(strict_types=1);
namespace App;

final class Http
{
    public static function secureHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Cross-Origin-Resource-Policy: same-origin');
        header_remove('X-Powered-By');
        if (Security::isHttps()) { header('Strict-Transport-Security: max-age=31536000; includeSubDomains'); }
    }

    public static function ok($data = null, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Mailer::flush();   // رسائل البريد المجدولة تتبعت بعد ما الرد يوصل
        exit;
    }

    public static function fail(string $code, string $message, int $status): void
    {
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        Mailer::flush();   // رسائل البريد المجدولة تتبعت بعد ما الرد يوصل
        exit;
    }

    /** يقرأ JSON بحد أقصى 64KB (أو $max لطلبات رفع الصور). */
    public static function body(int $max = 65536): array
    {
        $raw = file_get_contents('php://input', false, null, 0, $max + 1);
        if ($raw === false || $raw === '') { return []; }
        if (strlen($raw) > $max) { throw new ApiException('too_large', 'الطلب كبير جدًا.', 413); }
        try {
            $d = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException('bad_json', 'صيغة الطلب غير صحيحة.', 400);
        }
        if (!is_array($d)) { throw new ApiException('bad_json', 'صيغة الطلب غير صحيحة.', 400); }
        return $d;
    }
}
