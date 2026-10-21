<?php
declare(strict_types=1);
namespace App;

/**
 * سجل الموقع الشامل (كاميرا المراقبة). الإضافة فقط (append-only): مفيش أي دالة تحذف أو تعدّل.
 * كل صف يحمل hash مرتبط بالصف اللي قبله، فأي حذف/تعديل من برّه يتكشف من "فحص سلامة السجل".
 */
final class Audit
{
    public const SEVERITIES = ['info' => 'معلومة', 'notice' => 'ملاحظة', 'warning' => 'تحذير', 'critical' => 'حرج'];

    /** كود الـTAG => الاسم العربي (بيظهر للمالك ويُستخدم في التصفية) */
    public const TAGS = [
        'login' => 'تسجيل دخول', 'logout' => 'تسجيل خروج', 'login_failed' => 'فشل تسجيل دخول', 'account_locked' => 'قفل حساب مؤقت',
        'register' => 'إنشاء حساب', 'password_change' => 'تغيير كلمة المرور', 'profile_update' => 'تعديل الملف الشخصي', 'avatar' => 'الصورة الشخصية',
        'email_verify' => 'توثيق البريد', 'mail_sent' => 'بريد مُرسل', 'mail_failed' => 'فشل إرسال بريد',
        'account_disabled' => 'تعطيل حساب', 'account_enabled' => 'تفعيل حساب', 'account_edit' => 'تعديل حساب', 'account_delete' => 'حذف حساب',
        'staff_create' => 'إنشاء حساب إداري', 'staff_appoint' => 'تعيين إداري', 'staff_remove' => 'إزالة إداري', 'perms_change' => 'تعديل صلاحيات',
        'place_add' => 'إضافة مكان', 'place_edit' => 'تعديل مكان', 'place_remove' => 'إزالة مكان',
        'explore' => 'استكشف بورسعيد', 'upload' => 'لقطات الزوار', 'visit' => 'زيارة مكان', 'coupon' => 'كوبون',
        'share' => 'شير واكسب', 'notification' => 'الإشعارات', 'menu' => 'المنيو والعروض', 'verify' => 'توثيق',
        'access_denied' => 'رفض صلاحية', 'security' => 'أمني', 'setup' => 'إعداد النظام', 'logs_view' => 'عرض السجل', 'error' => 'خطأ سيرفر',
    ];

    private const ROLE_WORD = ['owner' => 'المالك', 'admin' => 'الأدمن', 'merchant' => 'المحل', 'user' => 'المستخدم'];

    /** اسم الفاعل مع دوره، مثال: "المالك أحمد" — يقبل id أو صف مستخدم أو null. */
    public static function who($actor): string
    {
        $a = self::resolve($actor);
        if (!$a['id']) { return 'زائر'; }
        return (self::ROLE_WORD[$a['role'] ?? 'user'] ?? 'المستخدم') . ' ' . $a['name'];
    }

    private static function resolve($actor): array
    {
        $none = ['id' => null, 'name' => 'زائر', 'role' => null];
        if ($actor === null) { return $none; }
        if (is_array($actor)) {
            if (isset($actor['id'], $actor['name'], $actor['role'])) { return ['id' => (int)$actor['id'], 'name' => (string)$actor['name'], 'role' => (string)$actor['role']]; }
            $actor = $actor['id'] ?? null;
            if ($actor === null) { return $none; }
        }
        try {
            $r = Db::one('SELECT u.id, u.name, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [(int)$actor]);
        } catch (\Throwable $e) { $r = null; }
        return $r ? ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'role' => (string)$r['role']] : $none;
    }

    /**
     * تسجيل حدث. $dedupSec > 0: لو نفس الـTAG من نفس الـIP اتسجّل خلال المدة دي نتجاهله (يمنع إغراق السجل أثناء الهجمات).
     * لا يرمي استثناء أبدًا: فشل التسجيل لا يكسر الطلب.
     */
    public static function event($actor, string $tag, string $sev, string $desc, array $meta = [], int $dedupSec = 0): void
    {
        $locked = false;
        try {
            $a = self::resolve($actor);
            if (!isset(self::SEVERITIES[$sev])) { $sev = 'info'; }
            $ip = Security::ipBin();
            $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $desc = mb_substr($desc, 0, 600);
            $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
            if ($dedupSec > 0 && Db::one('SELECT 1 AS x FROM activity_log WHERE tag = ? AND ip <=> ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND) LIMIT 1', [$tag, $ip, $dedupSec])) { return; }
            $lk = Db::one("SELECT GET_LOCK('ps_activity_chain', 5) AS l");
            $locked = $lk && (int)$lk['l'] === 1;
            $prevRow = Db::one('SELECT row_hash FROM activity_log ORDER BY id DESC LIMIT 1');
            $prev = $prevRow ? (string)$prevRow['row_hash'] : str_repeat('0', 64);
            $at = gmdate('Y-m-d H:i:s');
            $hash = self::hash($prev, $a['id'], $a['name'], $a['role'], $tag, $sev, $desc, $metaJson, $ip, $at);
            Db::run(
                'INSERT INTO activity_log (actor_id, actor_name, actor_role, tag, severity, description, meta, ip, user_agent, created_at, prev_hash, row_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$a['id'], $a['name'], $a['role'], $tag, $sev, $desc, $metaJson, $ip, $ua, $at, $prev, $hash]
            );
        } catch (\Throwable $e) {
            error_log('activity_log failed: ' . $e->getMessage());
        }
        if ($locked) { try { Db::one("SELECT RELEASE_LOCK('ps_activity_chain') AS r"); } catch (\Throwable $e) {} }
    }

    /** بصمة الصف: تعتمد على بصمة السابق + محتوى الصف (تُستخدم أيضًا في فحص السلامة). */
    public static function hash(string $prev, $actorId, string $name, ?string $role, string $tag, string $sev, string $desc, ?string $meta, ?string $ip, string $at): string
    {
        return hash('sha256', implode("\x1f", [$prev, (string)$actorId, $name, (string)$role, $tag, $sev, $desc, (string)$meta, $ip === null ? '' : bin2hex($ip), $at]));
    }

    /* ---- واجهة قديمة: الأكواد القديمة (action) تتحوّل لأحداث بـTAG وخطورة ووصف عربي ---- */
    private const LEGACY = [
        'user.register'         => ['register', 'info', 'قام %s بإنشاء حساب جديد'],
        'user.login'            => ['login', 'info', 'قام %s بتسجيل الدخول'],
        'user.logout'           => ['logout', 'info', 'قام %s بتسجيل الخروج'],
        'user.login_failed'     => ['login_failed', 'warning', 'محاولة تسجيل دخول فاشلة إلى حساب %s'],
        'user.password_changed' => ['password_change', 'notice', 'قام %s بتغيير كلمة المرور'],
        'admin.setup'           => ['setup', 'critical', 'تم تفعيل حساب المالك الأول من صفحة الإعداد'],
        'visit.create'          => ['visit', 'info', 'قام %s بتسجيل زيارة'],
        'coupon.create'         => ['coupon', 'info', 'قام %s بطلب كوبون خصم'],
        'coupon.redeem'         => ['coupon', 'notice', 'قام %s بتسوية كوبون خصم'],
        'review.save'           => ['visit', 'info', 'قام %s بتقييم مكان'],
        'share.app'             => ['visit', 'info', 'قام %s بمشاركة الموقع'],
        'explore.upload'        => ['upload', 'info', 'قام %s برفع صورة إلى استكشف بورسعيد'],
    ];

    public static function log(?int $actorId, string $action, ?string $entity = null, ?int $entityId = null, array $meta = []): void
    {
        $m = self::LEGACY[$action] ?? ['security', 'info', 'حدث: ' . $action . ' (%s)'];
        $desc = sprintf($m[2], self::who($actorId));
        if ($entity === 'place' && $entityId) {
            try { $p = Db::one('SELECT name_ar FROM places WHERE id = ?', [$entityId]); if ($p) { $desc .= ' «' . $p['name_ar'] . '»'; } } catch (\Throwable $e) {}
        }
        if ($entity) { $meta['entity'] = $entity; }
        if ($entityId) { $meta['entity_id'] = $entityId; }
        self::event($actorId, $m[0], $m[1], $desc, $meta);
    }
}
