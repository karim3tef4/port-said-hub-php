<?php
declare(strict_types=1);
namespace App;

/**
 * الإشعارات: الإدارة بتكتب رسالة واحدة وبتوصل لكل الحسابات (زوار + تجار)،
 * وعروض التجار بتتحوّل لإشعارات بتوصل للعملاء. حالة "اتقرا" متخزنة لكل مستخدم لوحده.
 */
final class Notifications
{
    /** الإشعارات الظاهرة: مش محذوفة ومش منتهية، وآخر 30 يوم بس. */
    private const LIVE = "n.deleted_at IS NULL AND (n.expires_at IS NULL OR n.expires_at > UTC_TIMESTAMP()) AND n.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)";

    /** نص حر (بيحافظ على الأسطر الجديدة) بحدود طول ومن غير رموز تحكم. */
    private static function text($v, int $min, int $max, string $label): string
    {
        if (!is_string($v)) { throw new ApiException('validation', "$label مطلوب.", 422); }
        $v = trim(str_replace("\r", '', $v));
        $v = (string)preg_replace("/\n{3,}/", "\n\n", $v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) { throw new ApiException('validation', "$label لازم يكون من $min إلى $max حرف.", 422); }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $v)) { throw new ApiException('validation', "$label فيه رموز غير مسموحة.", 422); }
        return $v;
    }

    /** إشعار شخصي لمستخدم واحد بس (مثلًا بعد ما يتوثق). بتتنادى من كلاسات تانية (مش endpoint). */
    public static function sendTo(int $userId, string $title, string $body, string $kind = 'admin'): void
    {
        $id = Security::uuid();
        Db::insert('INSERT INTO notifications (uuid, kind, title, body, target_user_id, created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())', [$id, $kind, $title, $body, $userId]);
    }

    /* ---------------- العميل ---------------- */

    /** قائمة إشعاراتي + عدد غير المقروء (الجرس). */
    public static function index(): void
    {
        $u = Auth::need();
        RateLimiter::hit('notifs:' . $u['id'], 300, 3600);
        $rows = Db::all(
            'SELECT n.uuid AS id, n.kind, n.title AS t, n.body AS b, n.created_at AS ts, p.uuid AS place_id, p.name_ar AS place_name, (r.user_id IS NOT NULL) AS is_read
             FROM notifications n
             LEFT JOIN places p ON p.id = n.place_id AND p.deleted_at IS NULL AND p.status = \'active\'
             LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
             WHERE ' . self::LIVE . ' AND (n.target_user_id IS NULL OR n.target_user_id = ?)
             ORDER BY n.id DESC LIMIT 40',
            [$u['id'], $u['id']]
        );
        $unread = 0;
        foreach ($rows as &$r) {
            $r['read'] = (bool)$r['is_read'];
            unset($r['is_read']);
            if (!$r['read']) { $unread++; }
        }
        unset($r);
        Http::ok(['items' => $rows, 'unread' => $unread]);
    }

    /** تعليم إشعار (أو الكل) كمقروء. */
    public static function markRead(): void
    {
        $u = Auth::need();
        RateLimiter::hit('notifread:' . $u['id'], 120, 3600);
        $b = Http::body();
        if (!empty($b['all'])) {
            Db::run(
                'INSERT IGNORE INTO notification_reads (user_id, notification_id)
                 SELECT ?, n.id FROM notifications n WHERE ' . self::LIVE,
                [$u['id']]
            );
        } else {
            $id = Validator::uuid($b['id'] ?? null);
            Db::run('INSERT IGNORE INTO notification_reads (user_id, notification_id) SELECT ?, n.id FROM notifications n WHERE n.uuid = ?', [$u['id'], $id]);
        }
        Http::ok(['read' => true]);
    }

    /* ---------------- الأدمن ---------------- */

    /** الإشعارات اللي اتبعتت (مع عدد اللي قراها). */
    public static function adminList(): void
    {
        Auth::needPerm('notifications.send');
        $rows = Db::all(
            "SELECT n.uuid AS id, n.kind, n.title, n.body, n.created_at, n.expires_at, p.name_ar AS place_name,
                    (SELECT COUNT(*) FROM notification_reads r WHERE r.notification_id = n.id) AS reads_count,
                    (n.expires_at IS NOT NULL AND n.expires_at <= UTC_TIMESTAMP()) AS expired
             FROM notifications n LEFT JOIN places p ON p.id = n.place_id
             WHERE n.deleted_at IS NULL ORDER BY n.id DESC LIMIT 100"
        );
        foreach ($rows as &$r) { $r['reads_count'] = (int)$r['reads_count']; $r['expired'] = (bool)$r['expired']; }
        unset($r);
        $rec = (int)(Db::one("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code IN ('user','merchant') AND u.deleted_at IS NULL AND u.status = 'active'")['n'] ?? 0);
        Http::ok(['items' => $rows, 'recipients' => $rec]);
    }

    /** كتابة إشعار جديد بيوصل لكل الحسابات. */
    public static function adminSend(): void
    {
        $me = Auth::needPerm('notifications.send');
        RateLimiter::hit('notifsend:' . $me['id'], 20, 3600);
        $b = Http::body();
        $title = Validator::str($b['title'] ?? null, 2, 120, 'عنوان الإشعار');
        $body = self::text($b['body'] ?? null, 3, 1000, 'نص الإشعار');
        $days = Validator::int($b['days'] ?? 0, 0, 90, 'مدة الظهور');   // 0 = من غير انتهاء (يختفي تلقائيًا بعد 30 يوم)
        $id = Security::uuid();
        if ($days > 0) {
            Db::run('INSERT INTO notifications (uuid, kind, title, body, created_by, expires_at, created_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY), UTC_TIMESTAMP())', [$id, 'admin', $title, $body, $me['id'], $days]);
        } else {
            Db::run('INSERT INTO notifications (uuid, kind, title, body, created_by, expires_at, created_at) VALUES (?,?,?,?,?,NULL, UTC_TIMESTAMP())', [$id, 'admin', $title, $body, $me['id']]);
        }
        Audit::event($me, 'notification', 'notice', 'قام ' . Audit::who($me) . ' بإرسال إشعار لكل الحسابات بعنوان «' . $title . '»', ['uuid' => $id]);
        Http::ok(['id' => $id], 201);
    }

    /** حذف إشعار (بيختفي عند كل الحسابات). لو كان عرض تاجر بيتشال العرض كمان. */
    public static function adminDelete(): void
    {
        $me = Auth::needPerm('notifications.send');
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $n = Db::one('SELECT id, title FROM notifications WHERE uuid = ? AND deleted_at IS NULL', [$id]);
        if (!$n) { throw new ApiException('not_found', 'الإشعار مش موجود.', 404); }
        Db::run('UPDATE notifications SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$n['id']]);
        Db::run("UPDATE place_offers SET status = 'removed' WHERE notification_id = ?", [$n['id']]);
        Audit::event($me, 'notification', 'notice', 'قام ' . Audit::who($me) . ' بحذف الإشعار «' . $n['title'] . '»', ['uuid' => $id]);
        Http::ok(['deleted' => true]);
    }
}
