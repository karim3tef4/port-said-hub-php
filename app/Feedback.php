<?php
declare(strict_types=1);
namespace App;

/** الشكاوى والاقتراحات: المستخدم يبعت، والأدمن (بصلاحية feedback.manage) يقرأ ويغيّر الحالة. */
final class Feedback
{
    private static bool $ready = false;

    /** بيعمل الجدول تلقائيًا أول مرة (نفس محتوى database/16_feedback.sql)، فمفيش حاجة لازم تتشغّل يدوي. */
    private static function ensure(): void
    {
        if (self::$ready) { return; }
        Db::run("CREATE TABLE IF NOT EXISTS feedback (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid       CHAR(36) NOT NULL,
            user_id    BIGINT UNSIGNED NOT NULL,
            kind       ENUM('complaint','suggestion') NOT NULL,
            message    VARCHAR(1000) NOT NULL,
            status     ENUM('new','read','done') NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_feedback_uuid (uuid),
            KEY ix_feedback_status (status, id),
            CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ready = true;
    }

    /** المستخدم: يبعت شكوى أو اقتراح. */
    public static function submit(): void
    {
        $u = Auth::need();
        RateLimiter::hit('fb:' . $u['id'], 5, 3600);
        $b = Http::body();
        $kind = Validator::enum($b['kind'] ?? null, ['complaint', 'suggestion'], 'النوع');
        $msg = Validator::str($b['message'] ?? null, 5, 1000, 'الرسالة');
        self::ensure();
        Db::run('INSERT INTO feedback (uuid, user_id, kind, message) VALUES (?,?,?,?)', [Security::uuid(), $u['id'], $kind, $msg]);
        Audit::log((int)$u['id'], 'feedback.new', 'feedback', null, ['kind' => $kind]);
        Http::ok(['sent' => true], 201);
    }

    /** الأدمن: قائمة الشكاوى والاقتراحات (الجديدة الأول). */
    public static function adminList(): void
    {
        Auth::needPerm('feedback.manage');
        self::ensure();
        $rows = Db::all(
            "SELECT f.uuid AS id, f.kind, f.message, f.status, f.created_at, u.name AS user_name, u.phone AS user_phone, u.email AS user_email
             FROM feedback f JOIN users u ON u.id = f.user_id
             ORDER BY (f.status = 'new') DESC, f.id DESC LIMIT 200"
        );
        Http::ok(['items' => $rows]);
    }

    /** الأدمن: حذف شكوى/اقتراح نهائيًا. */
    public static function adminDelete(): void
    {
        $me = Auth::needPerm('feedback.manage');
        $id = Validator::uuid(Http::body()['id'] ?? null);
        self::ensure();
        $n = Db::run('DELETE FROM feedback WHERE uuid = ?', [$id]);
        if ($n < 1) { throw new ApiException('not_found', 'الرسالة مش موجودة.', 404); }
        Audit::event($me, 'feedback', 'notice', 'قام ' . Audit::who($me) . ' بحذف شكوى/اقتراح', ['uuid' => $id]);
        Http::ok(['deleted' => true]);
    }

    /** الأدمن: تغيير الحالة (جديد / اتقرأ / اتعالج). */
    public static function setStatus(): void
    {
        $me = Auth::needPerm('feedback.manage');
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $st = Validator::enum($b['status'] ?? null, ['new', 'read', 'done'], 'الحالة');
        self::ensure();
        $n = Db::run('UPDATE feedback SET status = ? WHERE uuid = ?', [$st, $id]);
        Audit::event($me, 'feedback', 'info', 'غيّر ' . Audit::who($me) . ' حالة شكوى/اقتراح', ['uuid' => $id, 'status' => $st, 'rows' => $n]);
        Http::ok(['status' => $st]);
    }
}
