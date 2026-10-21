<?php
declare(strict_types=1);
namespace App;

/** تحديد معدل الطلبات داخل قاعدة البيانات (بدون Redis/Cron: مناسب للاستضافة المجانية). */
final class RateLimiter
{
    public static function hit(string $key, int $max, int $windowSec): void
    {
        $b = hash('sha256', $key);
        Db::run('DELETE FROM rate_limits WHERE expires_at < UTC_TIMESTAMP() LIMIT 50');
        Db::run(
            'INSERT INTO rate_limits (bucket, hits, expires_at) VALUES (?, 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)) '
            . 'ON DUPLICATE KEY UPDATE hits = IF(expires_at < UTC_TIMESTAMP(), 1, hits + 1), '
            . 'expires_at = IF(expires_at < UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), expires_at)',
            [$b, $windowSec, $windowSec]
        );
        $r = Db::one('SELECT hits FROM rate_limits WHERE bucket = ?', [$b]);
        if ($r && (int)$r['hits'] > $max) {
            throw new ApiException('rate_limited', 'محاولات كتير، استنى شوية وحاول تاني.', 429);
        }
    }
}
