<?php
declare(strict_types=1);
namespace App;

final class Reviews
{
    private static function placeId(string $uuid): int
    {
        $p = Db::one("SELECT id FROM places WHERE uuid = ? AND deleted_at IS NULL AND status = 'active'", [$uuid]);
        if (!$p) { throw new ApiException('not_found', 'المكان مش موجود.', 404); }
        return (int)$p['id'];
    }

    /** تقييمات مكان: الكل بيشوفها بالاسم كامل. */
    public static function index(): void
    {
        $u = Auth::need();
        $pid = self::placeId(Validator::uuid($_GET['place'] ?? null));
        $rows = Db::all("SELECT r.uuid AS id, r.rating AS r, r.comment AS c, DATE(r.created_at) AS d, r.user_id AS uid, u.name AS n, (u.email_verified_at IS NOT NULL) AS v
                         FROM place_reviews r JOIN users u ON u.id = r.user_id
                         WHERE r.place_id = ? AND r.status = 'visible' ORDER BY r.id DESC LIMIT 60", [$pid]);
        $st = Db::one("SELECT COUNT(*) AS n, AVG(rating) AS a FROM place_reviews WHERE place_id = ? AND status = 'visible'", [$pid]);
        $mine = Db::one("SELECT rating AS r, comment AS c FROM place_reviews WHERE place_id = ? AND user_id = ?", [$pid, $u['id']]);
        $items = [];
        foreach ($rows as $r) {
            $full = trim((string)preg_replace('/\s+/u', ' ', (string)$r['n']));
            $items[] = ['id' => $r['id'], 'n' => $full, 'r' => (int)$r['r'], 'c' => $r['c'], 'd' => $r['d'], 'mine' => (int)$r['uid'] === (int)$u['id'], 'v' => (bool)$r['v']];
        }
        Http::ok([
            'count' => (int)$st['n'],
            'avg'   => (int)$st['n'] > 0 ? round((float)$st['a'], 1) : null,
            'items' => $items,
            'mine'  => $mine ? ['r' => (int)$mine['r'], 'c' => $mine['c']] : null,
        ]);
    }

    /** تقييم واحد لكل مستخدم لكل مكان (إعادة الإرسال بتعدّله). */
    public static function save(): void
    {
        $u = Auth::need();
        RateLimiter::hit('rev:' . $u['id'], 20, 3600);
        $b = Http::body();
        $pid = self::placeId(Validator::uuid($b['place_id'] ?? null));
        $rating = (int)($b['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) { throw new ApiException('validation', 'اختار تقييم من 1 لـ 5 نجوم.', 422); }
        $c = (isset($b['comment']) && trim((string)$b['comment']) !== '') ? Validator::str($b['comment'], 2, 300, 'التعليق') : null;
        Db::run('INSERT INTO place_reviews (uuid, place_id, user_id, rating, comment) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)', [Security::uuid(), $pid, $u['id'], $rating, $c]);
        Audit::log((int)$u['id'], 'review.save', 'place', $pid);
        Http::ok(['saved' => true], 201);
    }

    public static function remove(): void
    {
        $u = Auth::need();
        $b = Http::body();
        $pid = self::placeId(Validator::uuid($b['place_id'] ?? null));
        Db::run('DELETE FROM place_reviews WHERE place_id = ? AND user_id = ?', [$pid, $u['id']]);
        Http::ok(['deleted' => true]);
    }
}
