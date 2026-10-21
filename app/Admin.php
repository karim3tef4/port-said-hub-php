<?php
declare(strict_types=1);
namespace App;

final class Admin
{
    /** صف المستخدم الهدف مع دوره. */
    public static function target(string $uuid): array
    {
        $t = Db::one('SELECT u.id, u.uuid, u.name, u.email, u.email_verified_at, u.status, u.avatar, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.uuid = ? AND u.deleted_at IS NULL', [$uuid]);
        if (!$t) { throw new ApiException('not_found', 'المستخدم مش موجود.', 404); }
        return $t;
    }

    /** المالك لا يُمَس من أحد، والأدمن لا يديره إلا مالك، والمستخدم العادي حسب الصلاحية (تُفحص قبلها). */
    public static function guardTarget(array $me, array $t, string $what = 'العملية'): void
    {
        if ((int)$t['id'] === (int)$me['id'] && $what !== 'مراسلة') { throw new ApiException('forbidden', 'مينفعش تعمل ده على حسابك.', 403); }
        if ($t['role'] === 'owner' || ($t['role'] === 'admin' && $me['role'] !== 'owner')) {
            Audit::event($me, 'access_denied', 'critical', 'حاول ' . Audit::who($me) . ' تنفيذ ' . $what . ' على حساب إداري محمي: ' . $t['name']);
            throw new ApiException('forbidden', 'مش مسموح لك بالعملية دي على الحساب ده.', 403);
        }
    }

    /** تعطيل/تفعيل حساب مع السبب + إشعار بريدي. مشتركة بين تبويب الحسابات وتبويب الفريق. */
    public static function applyStatus(array $me, array $t, string $st, ?string $reason): void
    {
        self::guardTarget($me, $t, $st === 'suspended' ? 'تعطيل' : 'تفعيل');
        if ($st === 'suspended') {
            $reason = Validator::str($reason, 3, 200, 'سبب التعطيل');
            Db::run("UPDATE users SET status = 'suspended', disabled_reason = ?, disabled_at = UTC_TIMESTAMP(), disabled_by = ? WHERE id = ?", [$reason, $me['id'], $t['id']]);
            Audit::event($me, 'account_disabled', 'warning', 'قام ' . Audit::who($me) . ' بتعطيل حساب ' . $t['name'] . ' — السبب: ' . $reason, ['target' => $t['uuid'], 'target_role' => $t['role'], 'reason' => $reason]);
            if ($t['email']) { Mailer::notice((string)$t['email'], (string)$t['name'], 'تم تعطيل حسابك', "تم تعطيل حسابك في Port Said Hub.\nالسبب: $reason\nلو تعتقد إن ده حصل بالخطأ تواصل مع الإدارة."); }
        } else {
            Db::run("UPDATE users SET status = 'active', disabled_reason = NULL, disabled_at = NULL, disabled_by = NULL, failed_logins = 0, locked_until = NULL WHERE id = ?", [$t['id']]);
            Audit::event($me, 'account_enabled', 'notice', 'قام ' . Audit::who($me) . ' بإعادة تفعيل حساب ' . $t['name'], ['target' => $t['uuid'], 'target_role' => $t['role']]);
            if ($t['email']) { Mailer::notice((string)$t['email'], (string)$t['name'], 'تمت إعادة تفعيل حسابك', 'تمت إعادة تفعيل حسابك في Port Said Hub، تقدر تسجّل دخولك الآن.'); }
        }
    }

    /* ---- الإعداد الأول: يفعّل حساب المالك الافتراضي مرة واحدة فقط بكود سري من config.php ---- */
    private static function setupPending(): bool
    {
        return (bool)Db::one("SELECT 1 AS x FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'owner' AND u.password_hash = '!' LIMIT 1");
    }

    public static function setupStatus(): void { Http::ok(['pending' => self::setupPending()]); }

    public static function setup(): void
    {
        RateLimiter::hit('setup:' . Security::ip(), 5, 3600);
        $tok = (string)Config::get('app.setup_token', '');
        $b = Http::body();
        if (strlen($tok) < 32 || !self::setupPending() || !is_string($b['token'] ?? null) || !hash_equals($tok, $b['token'])) {
            Audit::event(null, 'security', 'critical', 'محاولة فاشلة لاستخدام صفحة الإعداد الأول', [], 300);
            throw new ApiException('forbidden', 'الإعداد غير متاح.', 403);
        }
        $email = Validator::email($b['email'] ?? null);
        $pw = Validator::password($b['password'] ?? null, $email, 'owner');
        $n = Db::run(
            "UPDATE users SET email = ?, password_hash = ?, must_change_password = 0 WHERE password_hash = '!' AND role_id = (SELECT id FROM roles WHERE code = 'owner')",
            [$email, Security::hashPassword($pw)]
        );
        if ($n < 1) { throw new ApiException('forbidden', 'الإعداد غير متاح.', 403); }
        Audit::log(null, 'admin.setup', 'user', null);
        Http::ok(['done' => true]);
    }

    /* ---- بيانات الجلسة الإدارية ---- */
    public static function me(): void
    {
        $u = Auth::needStaff();
        Http::ok(['user' => Auth::publicUser($u), 'catalog' => Perms::catalog()]);
    }

    /** الصفحة الرئيسية للوحة: الكروت تختلف حسب صلاحيات الحساب، والمالك يشوف زيادة ملخص الأمان وآخر الأحداث. */
    public static function home(): void
    {
        $u = Auth::needStaff();
        $n = static fn(string $sql, array $a = []) => (int)(Db::one($sql, $a)['n'] ?? 0);
        $cards = [];
        if (Auth::can('users.view', $u)) {
            $cards[] = ['label' => 'المستخدمين', 'value' => $n("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'user' AND u.deleted_at IS NULL"), 'icon' => 'fa-users', 'tab' => 'users'];
            $cards[] = ['label' => 'جدد آخر 7 أيام', 'value' => $n("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'user' AND u.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"), 'icon' => 'fa-user-plus', 'tab' => 'users'];
            $cards[] = ['label' => 'حسابات معطّلة', 'value' => $n("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'user' AND u.deleted_at IS NULL AND u.status = 'suspended'"), 'icon' => 'fa-user-lock', 'tab' => 'users'];
        }
        if (Auth::can('places.view', $u)) {
            $cards[] = ['label' => 'الأماكن', 'value' => $n('SELECT COUNT(*) AS n FROM places WHERE deleted_at IS NULL'), 'icon' => 'fa-location-dot', 'tab' => 'places'];
        }
        if (Auth::can('uploads.review', $u)) {
            $cards[] = ['label' => 'صور بانتظار المراجعة', 'value' => $n("SELECT COUNT(*) AS n FROM explore_uploads WHERE status = 'pending'"), 'icon' => 'fa-images', 'tab' => 'uploads'];
        }
        if (Auth::can('coupons.redeem', $u) || Auth::can('stats.view', $u)) {
            $cards[] = ['label' => 'كوبونات نشطة', 'value' => $n("SELECT COUNT(*) AS n FROM coupons WHERE status='active' AND expires_at > UTC_TIMESTAMP()"), 'icon' => 'fa-ticket', 'tab' => Auth::can('coupons.redeem', $u) ? 'redeem' : 'stats'];
        }
        if (Auth::can('stats.view', $u)) {
            $cards[] = ['label' => 'الزيارات', 'value' => $n('SELECT COUNT(*) AS n FROM visits'), 'icon' => 'fa-shoe-prints', 'tab' => 'stats'];
        }
        $out = ['role' => $u['role'], 'cards' => $cards, 'recent' => [], 'security' => null];
        if ($u['role'] === 'owner') {
            $out['security'] = [
                ['label' => 'أحداث آخر 24 ساعة', 'value' => $n('SELECT COUNT(*) AS n FROM activity_log WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)'), 'icon' => 'fa-video'],
                ['label' => 'أحداث حرجة (24 ساعة)', 'value' => $n("SELECT COUNT(*) AS n FROM activity_log WHERE severity = 'critical' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"), 'icon' => 'fa-triangle-exclamation'],
                ['label' => 'محاولات دخول فاشلة (24 ساعة)', 'value' => $n("SELECT COUNT(*) AS n FROM activity_log WHERE tag = 'login_failed' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"), 'icon' => 'fa-user-shield'],
                ['label' => 'أدمن نشط', 'value' => $n("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.deleted_at IS NULL AND u.status = 'active'"), 'icon' => 'fa-user-gear'],
                ['label' => 'حسابات المالك', 'value' => $n("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'owner' AND u.deleted_at IS NULL"), 'icon' => 'fa-crown'],
            ];
            $out['recent'] = Logs::rows('1=1', [], 8, 0);
        }
        Http::ok($out);
    }

    /* ---- الإحصائيات ---- */
    public static function stats(): void
    {
        Auth::needPerm('stats.view');
        $q = static fn(string $sql) => Db::one($sql) ?? [];
        Http::ok([
            'users'        => (int)($q("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'user' AND u.deleted_at IS NULL")['n'] ?? 0),
            'new_7d'       => (int)($q("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'user' AND u.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)")['n'] ?? 0),
            'places'       => (int)($q("SELECT COUNT(*) AS n FROM places WHERE deleted_at IS NULL")['n'] ?? 0),
            'visits'       => (int)($q("SELECT COUNT(*) AS n FROM visits")['n'] ?? 0),
            'points_given' => (int)($q("SELECT COALESCE(SUM(delta),0) AS n FROM points_ledger WHERE delta > 0")['n'] ?? 0),
            'coupons_active'   => (int)($q("SELECT COUNT(*) AS n FROM coupons WHERE status='active' AND expires_at > UTC_TIMESTAMP()")['n'] ?? 0),
            'coupons_redeemed' => (int)($q("SELECT COUNT(*) AS n FROM coupons WHERE status='redeemed'")['n'] ?? 0),
            'commission_total' => (float)($q("SELECT COALESCE(SUM(amount),0) AS n FROM commissions")['n'] ?? 0),
            'commission_pending' => (float)($q("SELECT COALESCE(SUM(amount),0) AS n FROM commissions WHERE status='pending'")['n'] ?? 0),
            'by_type' => Db::all("SELECT ut.name_ar AS name, COUNT(*) AS n FROM users u JOIN user_types ut ON ut.id = u.user_type_id WHERE u.deleted_at IS NULL GROUP BY ut.id, ut.name_ar"),
            'by_gender' => Db::all("SELECT gender, COUNT(*) AS n FROM users WHERE gender IS NOT NULL AND deleted_at IS NULL GROUP BY gender"),
        ]);
    }

    /** أكتر الأقسام والأماكن دخولًا. days=1 النهارده، 7، 30، أو 0 = الكل. */
    public static function usage(): void
    {
        Auth::needPerm('stats.view');
        $days = isset($_GET['days']) ? max(0, min(3650, (int)$_GET['days'])) : 30;
        $cut = '';
        $args = [];
        if ($days > 0) {
            $cut = ' AND day >= ?';
            $args[] = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Cairo')))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        }
        $sections = Db::all("SELECT ref AS code, SUM(hits) AS n FROM usage_hits WHERE kind = 'section'$cut GROUP BY ref ORDER BY n DESC", $args);
        $places = Db::all(
            "SELECT p.name_ar AS name, c.code AS cat, SUM(h.hits) AS n FROM usage_hits h JOIN places p ON p.uuid = h.ref JOIN categories c ON c.id = p.category_id
             WHERE h.kind = 'place'" . str_replace('day >=', 'h.day >=', $cut) . ' GROUP BY h.ref, p.name_ar, c.code ORDER BY n DESC LIMIT 10',
            $args
        );
        Http::ok(['days' => $days, 'sections' => $sections, 'places' => $places]);
    }

    /* ---- الحسابات ---- */
    /** قائمة المسجلين (العملاء) مع العدد الكلي. */
    public static function users(): void
    {
        Auth::needPerm('users.view');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 25;
        $q = (isset($_GET['q']) && $_GET['q'] !== '') ? Validator::str($_GET['q'], 1, 60, 'البحث') : '';
        $args = [];
        $where = "u.deleted_at IS NULL AND r.code = 'user'";
        if (isset($_GET['status']) && in_array($_GET['status'], ['active', 'suspended'], true)) { $where .= ' AND u.status = ?'; $args[] = $_GET['status']; }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        $total = (int)Db::one("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE $where", $args)['n'];
        $rows = Db::all(
            "SELECT u.uuid AS id, u.name, u.phone, u.email, (u.email_verified_at IS NOT NULL) AS verified, r.code AS role, ut.name_ar AS type, i.name_ar AS institution, g.name_ar AS gov,
                    u.gender, u.points_balance AS points, u.status, u.disabled_reason, u.created_at, u.last_login_at
             FROM users u JOIN roles r ON r.id = u.role_id
             LEFT JOIN user_types ut ON ut.id = u.user_type_id
             LEFT JOIN institutions i ON i.id = u.institution_id
             LEFT JOIN governorates g ON g.id = u.governorate_id
             WHERE $where ORDER BY u.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
            $args
        );
        foreach ($rows as &$r) { $r['verified'] = (bool)$r['verified']; }
        unset($r);
        Http::ok(['users' => $rows, 'page' => $page, 'per' => $per, 'total' => $total,
                  'can' => ['edit' => Auth::can('users.edit'), 'suspend' => Auth::can('users.suspend'), 'delete' => Auth::can('users.delete'), 'mail' => Auth::can('users.mail'), 'points' => Auth::can('points.adjust')]]);
    }

    public static function updateUser(): void
    {
        $me = Auth::needPerm('users.edit');
        $b = Http::body();
        $t = self::target(Validator::uuid($b['id'] ?? null));
        self::guardTarget($me, $t, 'تعديل');
        $name = Validator::name($b['name'] ?? null);
        $phone = (isset($b['phone']) && trim((string)$b['phone']) !== '') ? Validator::phone($b['phone']) : null;
        $email = (isset($b['email']) && trim((string)$b['email']) !== '') ? Validator::email($b['email']) : null;
        $gender = (isset($b['gender']) && $b['gender'] !== '') ? Validator::enum($b['gender'], ['male', 'female'], 'الجنس') : null;
        if ($phone === null && $email === null) { throw new ApiException('validation', 'لازم رقم هاتف أو بريد إلكتروني على الأقل.', 422); }
        $emailChanged = $email !== $t['email'];
        try {
            Db::run('UPDATE users SET name = ?, phone = ?, email = ?, gender = ?' . ($emailChanged ? ', email_verified_at = NULL' : '') . ' WHERE id = ?', [$name, $phone, $email, $gender, $t['id']]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ApiException('conflict', strpos($e->getMessage(), 'uq_users_phone') !== false ? 'رقم الهاتف ده مسجّل لحساب تاني.' : 'الإيميل ده مسجّل لحساب تاني.', 409);
            }
            throw $e;
        }
        if ($emailChanged && $email !== null) { Profile::issueVerification((int)$t['id'], $email, $name); }
        Audit::event($me, 'account_edit', 'notice', 'قام ' . Audit::who($me) . ' بتعديل بيانات حساب ' . $t['name'] . ($name !== $t['name'] ? ' (الاسم الجديد: ' . $name . ')' : ''), ['target' => $t['uuid'], 'email_changed' => $emailChanged]);
        Http::ok(['saved' => true]);
    }

    public static function setUserStatus(): void
    {
        $me = Auth::needPerm('users.suspend');
        $b = Http::body();
        $t = self::target(Validator::uuid($b['id'] ?? null));
        $st = Validator::enum($b['status'] ?? null, ['active', 'suspended'], 'الحالة');
        if ($t['role'] !== 'user') { throw new ApiException('forbidden', 'حسابات الفريق بتتدار من تبويب «الفريق» (للمالك فقط).', 403); }
        self::applyStatus($me, $t, $st, isset($b['reason']) ? (string)$b['reason'] : null);
        Http::ok(['status' => $st]);
    }

    /** حذف حساب مستخدم: بيمسح بياناته الشخصية ويقفل الحساب، والرقم والإيميل يرجعوا متاحين لتسجيل جديد. سجل النقاط والزيارات بيفضل بدون اسم. */
    public static function deleteUser(): void
    {
        $me = Auth::needPerm('users.delete');
        $b = Http::body();
        $t = self::target(Validator::uuid($b['id'] ?? null));
        if ($t['role'] !== 'user') { throw new ApiException('forbidden', 'احذف صلاحيات الإداري الأول من تبويب «الفريق»، وبعدها تقدر تحذف الحساب.', 403); }
        self::guardTarget($me, $t, 'حذف');
        try {   // صور الزائر في "استكشف بورسعيد" والصورة الشخصية تتمسح مع حسابه
            $files = Db::all('SELECT file_name FROM explore_uploads WHERE user_id = ?', [$t['id']]);
            Db::run('DELETE FROM explore_uploads WHERE user_id = ?', [$t['id']]);
            foreach ($files as $f) { Catalog::dropUploadFile((string)$f['file_name']); }
            Profile::dropAvatarFile($t['avatar'] ?? null);
        } catch (\Throwable $e) {
            error_log('[delete-user uploads] ' . $e->getMessage());
        }
        Db::tx(function () use ($t) {
            Db::run("UPDATE coupons SET status = 'cancelled' WHERE user_id = ? AND status = 'active'", [$t['id']]);
            Db::run('DELETE FROM place_staff WHERE user_id = ?', [$t['id']]);
            Db::run('DELETE FROM email_tokens WHERE user_id = ?', [$t['id']]);
            Db::run("UPDATE users SET name = 'حساب محذوف', email = NULL, phone = NULL, avatar = NULL, email_verified_at = NULL, password_hash = '!', gender = NULL, user_type_id = NULL, governorate_id = NULL, institution_id = NULL, points_balance = 0, status = 'suspended', disabled_reason = 'تم حذف الحساب', deleted_at = UTC_TIMESTAMP() WHERE id = ?", [$t['id']]);
        });
        Audit::event($me, 'account_delete', 'critical', 'قام ' . Audit::who($me) . ' بحذف حساب المستخدم ' . $t['name'], ['target' => $t['uuid']]);
        Http::ok(['deleted' => true]);
    }

    /** مراسلة مستخدم بالبريد (PHPMailer). */
    public static function mailUser(): void
    {
        $me = Auth::needPerm('users.mail');
        RateLimiter::hit('mailuser:' . $me['id'], 30, 3600);
        $b = Http::body();
        $t = self::target(Validator::uuid($b['id'] ?? null));
        if ($t['role'] === 'owner' && $me['role'] !== 'owner') { throw new ApiException('forbidden', 'مش مسموح لك بالعملية دي على الحساب ده.', 403); }
        if (empty($t['email'])) { throw new ApiException('validation', 'المستخدم ده مالوش بريد إلكتروني.', 422); }
        $subject = Validator::str($b['subject'] ?? null, 3, 120, 'عنوان الرسالة');
        $msg = str_replace("\r", '', trim((string)($b['message'] ?? '')));
        if (mb_strlen($msg) < 5 || mb_strlen($msg) > 4000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $msg)) { throw new ApiException('validation', 'نص الرسالة لازم يكون من 5 إلى 4000 حرف.', 422); }
        if (!Mailer::enabled()) { throw new ApiException('mail_off', 'خدمة البريد غير مفعّلة. فعّل قسم mail في config.php وضع مكتبة PHPMailer.', 503); }
        $ok = Mailer::staffMessage((string)$t['email'], (string)$t['name'], $subject, $msg, Audit::who($me));
        if (!$ok) { throw new ApiException('mail_failed', 'تعذّر إرسال البريد (راجع إعدادات SMTP).', 502); }
        Audit::event($me, 'mail_sent', 'info', 'قام ' . Audit::who($me) . ' بإرسال بريد إلى ' . $t['name'] . ' بعنوان «' . $subject . '»', ['target' => $t['uuid']]);
        Http::ok(['sent' => true]);
    }

    /* ---- صور الزوار: مراجعة وقبول وحذف ---- */
    public static function uploadsList(): void
    {
        Auth::needPerm('uploads.review');
        $th = Catalog::hasImg() ? ', e.th_b64' : '';
        $rows = Db::all(
            "SELECT e.uuid AS id, e.file_name AS file, e.caption, e.status, e.created_at, u.name AS user_name, u.phone AS user_phone$th
             FROM explore_uploads e JOIN users u ON u.id = e.user_id ORDER BY (e.status = 'pending') DESC, e.id DESC LIMIT 100"
        );
        foreach ($rows as $i => $r) { $rows[$i]['th'] = !empty($r['th_b64']) ? 'data:image/jpeg;base64,' . $r['th_b64'] : null; unset($rows[$i]['th_b64']); }
        Http::ok(['items' => $rows]);
    }

    public static function approveUpload(): void
    {
        $me = Auth::needPerm('uploads.review');
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $r = Db::one('SELECT user_id, status FROM explore_uploads WHERE uuid = ?', [$id]);
        if (!$r) { throw new ApiException('not_found', 'الصورة مش موجودة.', 404); }
        $n = Db::run("UPDATE explore_uploads SET status = 'approved' WHERE uuid = ?", [$id]);
        if ($r['status'] !== 'approved') { Notifications::sendTo((int)$r['user_id'], 'ذكرياتك اتنشرت 📸', 'صورتك اتقبلت وبقت ظاهرة في «ذكرياتك». شكرًا إنك شاركتنا لحظاتك في بورسعيد!'); }
        Audit::event($me, 'upload', 'info', 'قام ' . Audit::who($me) . ' بقبول صورة زائر في ذكريات الزوار', ['uuid' => $id, 'rows' => $n]);
        Http::ok(['approved' => true]);
    }

    public static function rejectUpload(): void
    {
        $me = Auth::needPerm('uploads.review');
        $id = Validator::uuid(Http::body()['id'] ?? null);
        $r = Db::one('SELECT user_id FROM explore_uploads WHERE uuid = ?', [$id]);
        if (!$r) { throw new ApiException('not_found', 'الصورة مش موجودة.', 404); }
        try {
            Db::run("UPDATE explore_uploads SET status = 'rejected' WHERE uuid = ?", [$id]);
        } catch (\PDOException $e) {
            error_log('[uploads.reject] ' . $e->getMessage());
            throw new ApiException('setup', 'لازم تستورد ملف database/22_update12_memories_coupons_partners.sql الأول.', 500);
        }
        Notifications::sendTo((int)$r['user_id'], 'بخصوص صورتك في ذكرياتك', 'للأسف الصورة اللي رفعتها ماتنفعش تتنشر. تقدر ترفع صورة تانية من لحظاتك في بورسعيد.');
        Audit::event($me, 'upload', 'notice', 'قام ' . Audit::who($me) . ' برفض صورة زائر في ذكريات الزوار', ['uuid' => $id]);
        Http::ok(['rejected' => true]);
    }

    /** إنقاص نقاط حساب (بعدد معيّن) أو تصفير رصيده، مع تسجيل في سجل النقاط وإشعار للعميل. */
    public static function adjustPoints(): void
    {
        $me = Auth::needPerm('points.adjust');
        RateLimiter::hit('pts-adj:' . $me['id'], 60, 3600);
        $b = Http::body();
        $t = self::target(Validator::uuid($b['id'] ?? null));
        self::guardTarget($me, $t, 'تعديل النقاط');
        $mode = Validator::enum($b['mode'] ?? null, ['deduct', 'reset'], 'نوع العملية');
        $note = (isset($b['note']) && trim((string)$b['note']) !== '') ? Validator::str($b['note'], 2, 150, 'السبب') : null;
        $amount = $mode === 'deduct' ? Validator::int($b['amount'] ?? null, 1, 1000000, 'عدد النقاط') : 0;
        $res = Db::tx(static function () use ($t, $mode, $amount, $note) {
            $row = Db::one('SELECT points_balance FROM users WHERE id = ? FOR UPDATE', [$t['id']]);
            $bal = (int)$row['points_balance'];
            $d = $mode === 'reset' ? $bal : $amount;
            if ($d < 1) { throw new ApiException('validation', 'رصيد الحساب صفر أصلًا.', 422); }
            if ($d > $bal) { throw new ApiException('validation', 'رصيد الحساب ' . $bal . ' نقطة بس.', 422); }
            $new = $bal - $d;
            Db::run('UPDATE users SET points_balance = ? WHERE id = ?', [$new, $t['id']]);
            Db::run("INSERT INTO points_ledger (user_id, delta, balance_after, reason, ref_type, note) VALUES (?,?,?,'admin_adjust',?,?)", [$t['id'], -$d, $new, $mode === 'reset' ? 'admin_reset' : 'admin_deduct', $note]);
            return ['deducted' => $d, 'balance' => $new];
        });
        Notifications::sendTo((int)$t['id'], 'تعديل رصيد النقاط', ($mode === 'reset' ? 'تم تصفير رصيد نقاطك' : 'تم خصم ' . $res['deducted'] . ' نقطة من رصيدك') . ' من الإدارة. رصيدك الحالي: ' . $res['balance'] . ' نقطة.' . ($note ? "\nالسبب: " . $note : ''));
        Audit::event($me, 'points', 'notice', 'قام ' . Audit::who($me) . ($mode === 'reset' ? ' بتصفير نقاط ' : ' بخصم ' . $res['deducted'] . ' نقطة من ') . $t['name'], ['user' => $t['uuid'], 'deducted' => $res['deducted']]);
        Http::ok($res);
    }

    /** إضافة/استبدال/مسح لوجو مكان. */
    public static function placeLogo(): void
    {
        $me = Auth::needPerm('places.edit');
        $b = Http::body(3600000);
        $id = Validator::uuid($b['id'] ?? null);
        if (!Catalog::hasLogo()) { throw new ApiException('setup', 'استورد ملف database/23_update13_place_logos.sql من phpMyAdmin الأول.', 500); }
        $p = Db::one('SELECT id, name_ar FROM places WHERE uuid = ? AND deleted_at IS NULL', [$id]);
        if (!$p) { throw new ApiException('not_found', 'المكان مش موجود.', 404); }
        if (!empty($b['remove'])) {
            Db::run('UPDATE places SET logo_b64 = NULL WHERE id = ?', [$p['id']]);
            Audit::event($me, 'place_edit', 'info', 'قام ' . Audit::who($me) . ' بمسح لوجو «' . $p['name_ar'] . '»', ['uuid' => $id]);
            Http::ok(['removed' => true]);
        }
        if (!isset($b['image']) || trim((string)$b['image']) === '') { throw new ApiException('validation', 'اختار صورة من جهازك.', 422); }
        Db::run('UPDATE places SET logo_b64 = ? WHERE id = ?', [ImageUtil::toJpegB64((string)$b['image'], 600, 85), $p['id']]);
        Audit::event($me, 'place_edit', 'info', 'قام ' . Audit::who($me) . ' بتحديث لوجو «' . $p['name_ar'] . '»', ['uuid' => $id]);
        Http::ok(['saved' => true]);
    }

    /** سجل تسوية الكوبونات: مين استخدم خصم في أنهي مكان، وإجمالي عمولة كل مكان. */
    public static function couponsLog(): void
    {
        Auth::needPerm('coupons.redeem');
        $lim = isset($_GET['all']) ? 5000 : 300;
        $rows = Db::all(
            "SELECT c.uuid AS id, p.name_ar AS place, us.name AS customer, us.email AS customer_email,
                    c.discount_percent AS discount, c.points_cost AS points, c.redeemed_at,
                    rb.name AS redeemed_by, cm.amount AS commission
             FROM coupons c
             JOIN places p ON p.id = c.place_id
             JOIN users us ON us.id = c.user_id
             LEFT JOIN users rb ON rb.id = c.redeemed_by
             LEFT JOIN commissions cm ON cm.coupon_id = c.id
             WHERE c.status = 'redeemed'
             ORDER BY c.redeemed_at DESC, c.id DESC LIMIT $lim"
        );
        foreach ($rows as &$r) { $r['discount'] = (int)$r['discount']; $r['points'] = (int)$r['points']; $r['commission'] = (float)$r['commission']; }
        unset($r);
        $byPlace = Db::all(
            "SELECT p.name_ar AS place, COUNT(*) AS n, COALESCE(SUM(cm.amount), 0) AS commission
             FROM coupons c JOIN places p ON p.id = c.place_id LEFT JOIN commissions cm ON cm.coupon_id = c.id
             WHERE c.status = 'redeemed' GROUP BY p.id, p.name_ar ORDER BY n DESC LIMIT 100"
        );
        foreach ($byPlace as &$b) { $b['n'] = (int)$b['n']; $b['commission'] = (float)$b['commission']; }
        unset($b);
        Http::ok(['items' => $rows, 'by_place' => $byPlace]);
    }

    public static function deleteUpload(): void
    {
        $me = Auth::needPerm('uploads.review');
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $r = Db::one('SELECT id, file_name FROM explore_uploads WHERE uuid = ?', [$id]);
        if (!$r) { throw new ApiException('not_found', 'الصورة مش موجودة.', 404); }
        Db::run('DELETE FROM explore_uploads WHERE id = ?', [$r['id']]);
        Catalog::dropUploadFile((string)$r['file_name']);
        Audit::event($me, 'upload', 'notice', 'قام ' . Audit::who($me) . ' بحذف صورة زائر من استكشف بورسعيد', ['uuid' => $id]);
        Http::ok(['deleted' => true]);
    }

    /* ---- استكشف بورسعيد (صور/فيديوهات مرفوعة في htdocs/assets/explore) ---- */
    public static function exploreList(): void
    {
        Auth::needPerm('explore.manage');
        $items = Db::all('SELECT uuid AS id, kind, title_ar AS title, description_ar AS description, file_name AS file, (img_b64 IS NOT NULL) AS img, sort_order AS sort FROM explore_items ORDER BY sort_order, id');
        foreach ($items as &$x) { $x['img'] = (bool)$x['img']; }
        unset($x);
        Http::ok(['items' => $items]);
    }

    public static function saveExplore(): void
    {
        $me = Auth::needPerm('explore.manage');
        $b = Http::body(3600000);   // ممكن يبعت صورة مرفوعة فعليًا
        $title = Validator::str($b['title'] ?? null, 2, 150, 'العنوان');
        $desc = (isset($b['description']) && trim((string)$b['description']) !== '') ? Validator::text($b['description'], 2, 4000, 'الوصف') : null;
        $sort = Validator::int($b['sort'] ?? 0, 0, 9999, 'الترتيب');
        $id = Security::uuid();
        if (isset($b['image']) && trim((string)$b['image']) !== '') {
            // رفع صورة حقيقي: بيتخزن في القاعدة زي منيو المحلات، من غير الحاجة لـ FTP
            $jpg = ImageUtil::toJpegB64((string)$b['image'], 1920, 85);
            Db::insert('INSERT INTO explore_items (uuid, kind, title_ar, description_ar, img_b64, sort_order) VALUES (?,\'image\',?,?,?,?)', [$id, $title, $desc, $jpg, $sort]);
        } else {
            // الطريقة القديمة: فيديو (أو صورة) اترفعت يدويًا على الاستضافة واكتب اسم الملف هنا
            $file = trim((string)($b['file'] ?? ''));
            if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,140}\.(jpg|jpeg|png|webp|gif|mp4|webm)$~i', $file)) {
                throw new ApiException('validation', 'اختار صورة من جهازك، أو لو فيديو: ارفعه في فولدر assets/explore على الاستضافة واكتب اسمه هنا (ينتهي بـ mp4 أو webm).', 422);
            }
            if (!is_file(dirname(__DIR__) . '/assets/explore/' . $file)) {
                throw new ApiException('validation', 'الملف ده مش موجود في فولدر assets/explore على الاستضافة. ارفعه الأول وبعدين ضيفه هنا.', 422);
            }
            $kind = preg_match('~\.(mp4|webm)$~i', $file) ? 'video' : 'image';
            Db::insert('INSERT INTO explore_items (uuid, kind, title_ar, description_ar, file_name, sort_order) VALUES (?,?,?,?,?,?)', [$id, $kind, $title, $desc, $file, $sort]);
        }
        Audit::event($me, 'explore', 'info', 'قام ' . Audit::who($me) . ' بإضافة «' . $title . '» إلى استكشف بورسعيد', ['uuid' => $id]);
        Http::ok(['id' => $id], 201);
    }

    /** تعديل عنصر في استكشف بورسعيد (العنوان/الوصف/الترتيب، وممكن تغيير الصورة أو اسم الفيديو). */
    public static function updateExplore(): void
    {
        $me = Auth::needPerm('explore.manage');
        $b = Http::body(3600000);
        $id = Validator::uuid($b['id'] ?? null);
        $r = Db::one('SELECT id FROM explore_items WHERE uuid = ?', [$id]);
        if (!$r) { throw new ApiException('not_found', 'العنصر مش موجود.', 404); }
        $title = Validator::str($b['title'] ?? null, 2, 150, 'العنوان');
        $desc = (isset($b['description']) && trim((string)$b['description']) !== '') ? Validator::text($b['description'], 2, 4000, 'الوصف') : null;
        $sort = Validator::int($b['sort'] ?? 0, 0, 9999, 'الترتيب');
        $sets = ['title_ar = ?', 'description_ar = ?', 'sort_order = ?'];
        $args = [$title, $desc, $sort];
        if (isset($b['image']) && trim((string)$b['image']) !== '') {
            $sets[] = "kind = 'image'"; $sets[] = 'img_b64 = ?'; $sets[] = 'file_name = NULL';
            $args[] = ImageUtil::toJpegB64((string)$b['image'], 1920, 85);
        } elseif (isset($b['file']) && trim((string)$b['file']) !== '') {
            $file = trim((string)$b['file']);
            if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,140}\.(jpg|jpeg|png|webp|gif|mp4|webm)$~i', $file) || !is_file(dirname(__DIR__) . '/assets/explore/' . $file)) {
                throw new ApiException('validation', 'اسم الملف غلط أو الملف مش موجود في فولدر assets/explore على الاستضافة.', 422);
            }
            $sets[] = 'kind = ?'; $args[] = preg_match('~\.(mp4|webm)$~i', $file) ? 'video' : 'image';
            $sets[] = 'file_name = ?'; $args[] = $file;
            $sets[] = 'img_b64 = NULL';
        }
        $args[] = $r['id'];
        Db::run('UPDATE explore_items SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
        Audit::event($me, 'explore', 'info', 'قام ' . Audit::who($me) . ' بتعديل «' . $title . '» في استكشف بورسعيد', ['uuid' => $id]);
        Http::ok(['updated' => true]);
    }

    /* ---- لمحات من بورسعيد (الشريط العرضي في الصفحة الرئيسية) ---- */
    private static function galleryDb(callable $fn)
    {
        try { return $fn(); }
        catch (\PDOException $e) {
            error_log('[gallery] ' . $e->getMessage());
            throw new ApiException('setup', 'جدول اللمحات مش موجود. استورد ملف database/21_update10_gallery_share_offers.sql من phpMyAdmin الأول.', 500);
        }
    }

    public static function galleryList(): void
    {
        Auth::needPerm('explore.manage');
        $items = self::galleryDb(static fn() => Db::all('SELECT uuid AS id, caption, sort_order AS sort, LENGTH(img_b64) AS iv FROM gallery_items ORDER BY sort_order, id'));
        foreach ($items as &$x) { $x['iv'] = (int)$x['iv']; }
        unset($x);
        $static = count(glob(dirname(__DIR__) . '/assets/port-said/gallery/*.{webp,jpg,jpeg,png}', GLOB_BRACE) ?: []);
        Http::ok(['items' => $items, 'static_count' => $static]);
    }

    public static function galleryAdd(): void
    {
        $me = Auth::needPerm('explore.manage');
        $b = Http::body(3600000);
        $cap = (isset($b['caption']) && trim((string)$b['caption']) !== '') ? Validator::str($b['caption'], 2, 120, 'الوصف') : null;
        $sort = Validator::int($b['sort'] ?? 0, 0, 9999, 'الترتيب');
        if (!isset($b['image']) || trim((string)$b['image']) === '') { throw new ApiException('validation', 'اختار صورة من جهازك.', 422); }
        $jpg = ImageUtil::toJpegB64((string)$b['image'], 1600, 82);
        $id = Security::uuid();
        self::galleryDb(static function () use ($id, $cap, $jpg, $sort) {
            $n = (int)(Db::one('SELECT COUNT(*) AS n FROM gallery_items')['n'] ?? 0);
            if ($n >= 60) { throw new ApiException('too_many', 'وصلت للحد الأقصى (60 صورة). احذف صور قديمة الأول.', 409); }
            Db::insert('INSERT INTO gallery_items (uuid, caption, img_b64, sort_order) VALUES (?,?,?,?)', [$id, $cap, $jpg, $sort]);
        });
        Audit::event($me, 'explore', 'info', 'قام ' . Audit::who($me) . ' بإضافة صورة إلى «لمحات من بورسعيد»', ['uuid' => $id]);
        Http::ok(['id' => $id], 201);
    }

    public static function galleryUpdate(): void
    {
        $me = Auth::needPerm('explore.manage');
        $b = Http::body(3600000);
        $id = Validator::uuid($b['id'] ?? null);
        $cap = (isset($b['caption']) && trim((string)$b['caption']) !== '') ? Validator::str($b['caption'], 2, 120, 'الوصف') : null;
        $sort = Validator::int($b['sort'] ?? 0, 0, 9999, 'الترتيب');
        $sets = 'caption = ?, sort_order = ?';
        $args = [$cap, $sort];
        if (isset($b['image']) && trim((string)$b['image']) !== '') { $sets .= ', img_b64 = ?'; $args[] = ImageUtil::toJpegB64((string)$b['image'], 1600, 82); }
        $args[] = $id;
        $n = self::galleryDb(static fn() => Db::run("UPDATE gallery_items SET $sets WHERE uuid = ?", $args));
        if ($n < 1 && !self::galleryDb(static fn() => Db::one('SELECT 1 AS x FROM gallery_items WHERE uuid = ?', [$id]))) { throw new ApiException('not_found', 'الصورة مش موجودة.', 404); }
        Audit::event($me, 'explore', 'info', 'قام ' . Audit::who($me) . ' بتعديل صورة في «لمحات من بورسعيد»', ['uuid' => $id]);
        Http::ok(['updated' => true]);
    }

    public static function galleryDelete(): void
    {
        $me = Auth::needPerm('explore.manage');
        $id = Validator::uuid(Http::body()['id'] ?? null);
        $n = self::galleryDb(static fn() => Db::run('DELETE FROM gallery_items WHERE uuid = ?', [$id]));
        if ($n < 1) { throw new ApiException('not_found', 'الصورة مش موجودة.', 404); }
        Audit::event($me, 'explore', 'notice', 'قام ' . Audit::who($me) . ' بحذف صورة من «لمحات من بورسعيد»', ['uuid' => $id]);
        Http::ok(['deleted' => true]);
    }

    /* ---- شير واكسب ---- */
    public static function shareList(): void
    {
        Auth::needPerm('share.manage');
        $pts = max(0, (int)Settings::get('share_points', '40'));
        $rows = Db::all(
            "SELECT u.uuid AS id, u.name, u.email, u.points_balance AS balance,
                    MIN(CASE WHEN l.reason = 'share' THEN l.created_at END) AS shared_at,
                    SUM(l.reason = 'admin_adjust' AND l.ref_type = 'share_regrant') AS regrants
             FROM points_ledger l JOIN users u ON u.id = l.user_id
             WHERE u.deleted_at IS NULL
               AND u.id IN (SELECT user_id FROM points_ledger WHERE reason = 'share')
               AND (l.reason = 'share' OR (l.reason = 'admin_adjust' AND l.ref_type = 'share_regrant'))
             GROUP BY u.id, u.uuid, u.name, u.email, u.points_balance
             ORDER BY shared_at DESC LIMIT 500"
        );
        foreach ($rows as &$r) { $r['regrants'] = (int)$r['regrants']; $r['balance'] = (int)$r['balance']; }
        unset($r);
        $total = (int)(Db::one("SELECT COUNT(DISTINCT user_id) AS n FROM points_ledger WHERE reason = 'share'")['n'] ?? 0);
        Http::ok(['points' => $pts, 'total' => $total, 'items' => $rows]);
    }

    public static function shareSettings(): void
    {
        $me = Auth::needPerm('share.manage');
        $pts = Validator::int(Http::body()['points'] ?? null, 0, 1000, 'نقاط الشير');
        Db::run("INSERT INTO settings (k, v) VALUES ('share_points', ?) ON DUPLICATE KEY UPDATE v = ?", [(string)$pts, (string)$pts]);
        Audit::event($me, 'share', 'notice', 'قام ' . Audit::who($me) . ' بتغيير نقاط «شير واكسب» إلى ' . $pts . ' نقطة', ['points' => $pts]);
        Http::ok(['points' => $pts]);
    }

    /** إعادة تنزيل نقاط الشير للي شيروا قبل كده: لحساب واحد (id) أو لكلهم. */
    public static function shareRegrant(): void
    {
        $me = Auth::needPerm('share.manage');
        RateLimiter::hit('share-regrant:' . $me['id'], 12, 3600);
        $b = Http::body();
        $pts = max(0, (int)Settings::get('share_points', '40'));
        if ($pts < 1) { throw new ApiException('validation', 'نقاط الشير حاليًا صفر. غيّر القيمة الأول.', 422); }
        $sub = "u.id IN (SELECT user_id FROM points_ledger WHERE reason = 'share')";
        if (isset($b['id']) && $b['id'] !== '') {
            $ids = Db::all("SELECT u.id FROM users u WHERE u.uuid = ? AND u.deleted_at IS NULL AND $sub", [Validator::uuid($b['id'])]);
        } else {
            $ids = Db::all("SELECT u.id FROM users u WHERE u.deleted_at IS NULL AND u.status = 'active' AND $sub LIMIT 5000");
        }
        if (!$ids) { throw new ApiException('not_found', 'مفيش حسابات شيرت لسه.', 404); }
        $n = 0;
        foreach ($ids as $r) {
            Db::tx(static function () use ($r, $pts) {
                $row = Db::one('SELECT points_balance FROM users WHERE id = ? FOR UPDATE', [$r['id']]);
                $new = (int)$row['points_balance'] + $pts;
                Db::run('UPDATE users SET points_balance = ? WHERE id = ?', [$new, $r['id']]);
                Db::run("INSERT INTO points_ledger (user_id, delta, balance_after, reason, ref_type, note) VALUES (?,?,?,'admin_adjust','share_regrant','إعادة نقاط شير واكسب من الإدارة')", [$r['id'], $pts, $new]);
            });
            $n++;
        }
        Audit::event($me, 'share', 'notice', 'قام ' . Audit::who($me) . ' بإعادة تنزيل ' . $pts . ' نقطة «شير واكسب» لـ ' . $n . ' حساب', ['count' => $n, 'points' => $pts]);
        Http::ok(['count' => $n, 'points' => $pts]);
    }

    public static function deleteExplore(): void
    {
        $me = Auth::needPerm('explore.manage');
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $r = Db::one('SELECT title_ar FROM explore_items WHERE uuid = ?', [$id]);
        $n = Db::run('DELETE FROM explore_items WHERE uuid = ?', [$id]);
        if ($n < 1) { throw new ApiException('not_found', 'العنصر مش موجود.', 404); }
        Audit::event($me, 'explore', 'notice', 'قام ' . Audit::who($me) . ' بحذف «' . ($r['title_ar'] ?? '') . '» من استكشف بورسعيد', ['uuid' => $id]);
        Http::ok(['deleted' => true]);
    }

    /* ---- الأماكن والمطاعم ---- */
    public static function places(): void
    {
        Auth::needPerm('places.view');
        Http::ok(['places' => Db::all(
            "SELECT p.uuid AS id, c.code AS category, p.name_ar AS name, p.area_ar AS area, p.description_ar AS description, p.latitude AS lat, p.longitude AS lon,
                    p.visit_points AS points, p.discount_percent AS discount, p.coupon_cost_points AS cost, p.commission_amount AS commission, p.checkin_radius_m AS radius, p.status,
                    (SELECT COUNT(*) FROM visits v WHERE v.place_id = p.id) AS visits" . (Catalog::hasLogo() ? ', LENGTH(p.logo_b64) AS lg' : ', 0 AS lg') . "
             FROM places p JOIN categories c ON c.id = p.category_id WHERE p.deleted_at IS NULL ORDER BY p.id DESC"
        ), 'can' => ['add' => Auth::can('places.add'), 'edit' => Auth::can('places.edit'), 'remove' => Auth::can('places.remove')]]);
    }

    /**
     * الزيارات المؤكدة: مين زار أنهي مكان (معلم سياحي بالموقع، أو محل/مطعم بتأكيد الزبون).
     * ?place=uuid لمكان بعينه، ?cat=code لقسم، ?days=7|30|0 للفترة، ?q= بحث بالاسم/الرقم.
     */
    public static function visits(): void
    {
        Auth::needPerm('stats.view');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 30;
        $where = '1=1';
        $args = [];
        if (isset($_GET['place']) && $_GET['place'] !== '') { $where .= ' AND p.uuid = ?'; $args[] = Validator::uuid($_GET['place']); }
        if (isset($_GET['cat']) && $_GET['cat'] !== '') { $where .= ' AND c.code = ?'; $args[] = Validator::enum($_GET['cat'], array_column(Db::all('SELECT code FROM categories'), 'code'), 'القسم'); }
        $days = isset($_GET['days']) ? max(0, min(3650, (int)$_GET['days'])) : 30;
        if ($days > 0) {
            $where .= ' AND v.visit_day >= ?';
            $args[] = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Cairo')))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        }
        if (isset($_GET['q']) && $_GET['q'] !== '') {
            $q = Validator::str($_GET['q'], 1, 60, 'البحث');
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (u.name LIKE ? OR u.phone LIKE ? OR p.name_ar LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        $from = 'FROM visits v JOIN users u ON u.id = v.user_id JOIN places p ON p.id = v.place_id JOIN categories c ON c.id = p.category_id';
        $total = (int)Db::one("SELECT COUNT(*) AS n $from WHERE $where", $args)['n'];
        $rows = Db::all(
            "SELECT u.name AS user_name, u.phone AS user_phone, p.name_ar AS place_name, c.code AS cat, v.points_awarded AS points,
                    v.location_verified AS verified, v.distance_m AS dist, v.visited_at
             $from WHERE $where ORDER BY v.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
            $args
        );
        foreach ($rows as &$r) { $r['verified'] = (bool)$r['verified']; $r['points'] = (int)$r['points']; }
        unset($r);
        $top = Db::all(
            "SELECT p.name_ar AS name, c.code AS cat, COUNT(*) AS n $from WHERE $where GROUP BY p.id, p.name_ar, c.code ORDER BY n DESC LIMIT 10",
            $args
        );
        foreach ($top as &$t) { $t['n'] = (int)$t['n']; }
        unset($t);
        Http::ok(['items' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'top' => $top]);
    }

    public static function savePlace(): void
    {
        $b = Http::body();
        $editing = !empty($b['id']);
        $me = Auth::needPerm($editing ? 'places.edit' : 'places.add');
        $cat = Db::one('SELECT id FROM categories WHERE code = ?', [Validator::enum($b['category'] ?? null, array_column(Db::all('SELECT code FROM categories'), 'code'), 'التصنيف')]);
        $f = [
            Validator::str($b['name'] ?? null, 2, 150, 'الاسم'),
            Validator::str($b['area'] ?? null, 2, 100, 'المنطقة'),
            Validator::float($b['lat'] ?? null, -90, 90, true, 'خط العرض'),
            Validator::float($b['lon'] ?? null, -180, 180, true, 'خط الطول'),
            Validator::int($b['points'] ?? null, 0, 5000, 'نقاط الزيارة'),
            Validator::int($b['discount'] ?? null, 0, 100, 'نسبة الخصم'),
            Validator::int($b['cost'] ?? null, 0, 100000, 'تكلفة الكوبون'),
            Validator::float($b['commission'] ?? null, 0, 10000, true, 'العمولة'),
            Validator::enum($b['status'] ?? null, ['draft', 'active', 'hidden'], 'الحالة'),
        ];
        $desc = (isset($b['description']) && trim((string)$b['description']) !== '') ? Validator::str($b['description'], 2, 2000, 'الوصف') : null;
        $rad = (isset($b['radius']) && $b['radius'] !== '' && $b['radius'] !== null) ? Validator::int($b['radius'], 30, 500, 'نطاق تسجيل الزيارة (متر)') : null;
        if ($editing) {
            $id = Validator::uuid($b['id']);
            $n = Db::run(
                'UPDATE places SET category_id=?, name_ar=?, area_ar=?, latitude=?, longitude=?, visit_points=?, discount_percent=?, coupon_cost_points=?, commission_amount=?, status=?, description_ar=?, checkin_radius_m=COALESCE(?, checkin_radius_m), is_demo=0 WHERE uuid=? AND deleted_at IS NULL',
                [$cat['id'], $f[0], $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8], $desc, $rad, $id]
            );
            Audit::event($me, 'place_edit', 'notice', 'قام ' . Audit::who($me) . ' بتعديل المكان «' . $f[0] . '»', ['uuid' => $id, 'rows' => $n]);
            Http::ok(['id' => $id]);
        }
        $id = Security::uuid();
        Db::insert(
            'INSERT INTO places (uuid, category_id, name_ar, area_ar, latitude, longitude, visit_points, discount_percent, coupon_cost_points, commission_amount, status, description_ar, checkin_radius_m, is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,COALESCE(?, 150),0)',
            [$id, $cat['id'], $f[0], $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8], $desc, $rad]
        );
        Audit::event($me, 'place_add', 'info', 'قام ' . Audit::who($me) . ' بإضافة المكان «' . $f[0] . '»', ['uuid' => $id]);
        Http::ok(['id' => $id], 201);
    }

    /** إزالة مكان من الموقع (حذف ناعم: البيانات والسجل التاريخي يفضلوا). */
    public static function deletePlace(): void
    {
        $me = Auth::needPerm('places.remove');
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $p = Db::one('SELECT name_ar FROM places WHERE uuid = ? AND deleted_at IS NULL', [$id]);
        if (!$p) { throw new ApiException('not_found', 'المكان مش موجود.', 404); }
        Db::run("UPDATE places SET deleted_at = UTC_TIMESTAMP(), status = 'hidden' WHERE uuid = ?", [$id]);
        Audit::event($me, 'place_remove', 'warning', 'قام ' . Audit::who($me) . ' بإزالة المكان «' . $p['name_ar'] . '» من الموقع', ['uuid' => $id]);
        Http::ok(['deleted' => true]);
    }
}
