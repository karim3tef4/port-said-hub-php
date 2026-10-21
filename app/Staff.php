<?php
declare(strict_types=1);
namespace App;

/** إدارة فريق الإدارة (مالك/أدمن) — للمالك فقط. */
final class Staff
{
    public const MAX_OWNERS = 10;

    private static function roleId(string $code): int
    {
        $r = Db::one('SELECT id FROM roles WHERE code = ?', [$code]);
        if (!$r) { throw new ApiException('server', 'الأدوار غير مجهّزة. شغّل ملف 09_staff_logs_mail.sql.', 500); }
        return (int)$r['id'];
    }

    private static function ownerCount(): int
    {
        return (int)Db::one("SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'owner' AND u.deleted_at IS NULL")['n'];
    }

    private static function setPermRows(int $userId, array $perms, int $by): void
    {
        Db::run('DELETE FROM admin_permissions WHERE user_id = ?', [$userId]);
        foreach ($perms as $p) { Db::run('INSERT INTO admin_permissions (user_id, perm, granted_by) VALUES (?,?,?)', [$userId, $p, $by]); }
        Auth::forgetPerms();
    }

    public static function list(): void
    {
        Auth::needOwner();
        $rows = Db::all(
            "SELECT u.id AS rid, u.uuid AS id, u.name, u.email, u.phone, (u.email_verified_at IS NOT NULL) AS verified, r.code AS role, u.status, u.disabled_reason, u.last_login_at, u.created_at
             FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code IN ('owner','admin') AND u.deleted_at IS NULL ORDER BY (r.code = 'owner') DESC, u.id"
        );
        $perms = [];
        foreach (Db::all('SELECT user_id, perm FROM admin_permissions') as $p) { $perms[(int)$p['user_id']][] = $p['perm']; }
        foreach ($rows as &$r) {
            $r['perms'] = $r['role'] === 'owner' ? Perms::codes() : ($perms[(int)$r['rid']] ?? []);
            $r['verified'] = (bool)$r['verified'];
            unset($r['rid']);
        }
        unset($r);
        Http::ok(['staff' => $rows, 'catalog' => Perms::catalog(), 'owners' => self::ownerCount(), 'max_owners' => self::MAX_OWNERS]);
    }

    /** إنشاء حساب إداري جديد (أدمن بصلاحيات محددة، أو مالك حتى 10 كحد أقصى). */
    public static function create(): void
    {
        $me = Auth::needOwner();
        RateLimiter::hit('staffcreate:' . $me['id'], 20, 3600);
        $b = Http::body();
        $name = Validator::name($b['name'] ?? null);
        $email = Validator::email($b['email'] ?? null);
        $phone = (isset($b['phone']) && trim((string)$b['phone']) !== '') ? Validator::phone($b['phone']) : null;
        $pw = Validator::password($b['password'] ?? null, $email, $name);
        $role = Validator::enum($b['role'] ?? null, ['admin', 'owner'], 'الرتبة');
        $perms = $role === 'admin' ? Perms::normalize($b['perms'] ?? []) : [];
        $roleId = self::roleId($role);
        try {
            $id = Db::tx(function () use ($role, $roleId, $name, $email, $phone, $pw, $perms, $me) {
                if ($role === 'owner') {
                    Db::one("SELECT id FROM roles WHERE code = 'owner' FOR UPDATE");   // قفل يمنع تجاوز حد الـ 10 بطلبين متزامنين
                    if (self::ownerCount() >= self::MAX_OWNERS) { throw new ApiException('limit', 'وصلت للحد الأقصى: ' . self::MAX_OWNERS . ' حسابات مالك.', 409); }
                }
                $uid = Db::insert(
                    'INSERT INTO users (uuid, name, email, phone, password_hash, role_id, must_change_password) VALUES (?,?,?,?,?,?,1)',
                    [Security::uuid(), $name, $email, $phone, Security::hashPassword($pw), $roleId]
                );
                if ($perms) { self::setPermRows($uid, $perms, (int)$me['id']); }
                return $uid;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ApiException('conflict', strpos($e->getMessage(), 'uq_users_phone') !== false ? 'رقم الهاتف ده مسجّل لحساب تاني.' : 'الإيميل ده مسجّل لحساب تاني.', 409);
            }
            throw $e;
        }
        Audit::event($me, 'staff_create', $role === 'owner' ? 'critical' : 'warning',
            'قام ' . Audit::who($me) . ' بإنشاء حساب ' . ($role === 'owner' ? 'مالك' : 'أدمن') . ' جديد: ' . $name . ($perms ? ' (صلاحيات: ' . implode('، ', array_map([Perms::class, 'label'], $perms)) . ')' : ''),
            ['email' => Mailer::mask($email), 'perms' => $perms]);
        Mailer::notice($email, $name, 'تم إنشاء حساب إداري لك', 'تم إنشاء حساب ' . ($role === 'owner' ? 'مالك' : 'أدمن') . " لك في Port Said Hub.\nسجّل دخولك بهذا البريد وكلمة المرور التي أبلغك بها المالك، وسيُطلب منك تغييرها في أول دخول.");
        Profile::issueVerification($id, $email, $name);
        Http::ok(['id' => $id], 201);
    }

    /** تعيين مستخدم موجود (بالبريد أو الهاتف) كأدمن أو مالك. حسابه بيفضل شغّال كمستخدم عادي كمان. */
    public static function appoint(): void
    {
        $me = Auth::needOwner();
        $b = Http::body();
        $idf = is_string($b['identifier'] ?? null) ? trim($b['identifier']) : '';
        if ($idf === '') { throw new ApiException('validation', 'اكتب بريد أو رقم هاتف المستخدم.', 422); }
        $col = strpos($idf, '@') !== false ? 'email' : 'phone';
        $val = $col === 'email' ? Validator::email($idf) : Validator::phone($idf);
        $role = Validator::enum($b['role'] ?? null, ['admin', 'owner'], 'الرتبة');
        $perms = $role === 'admin' ? Perms::normalize($b['perms'] ?? []) : [];
        $t = Db::one("SELECT u.id, u.uuid, u.name, u.email, r.code AS role, u.status FROM users u JOIN roles r ON r.id = u.role_id WHERE u.$col = ? AND u.deleted_at IS NULL", [$val]);
        if (!$t) { throw new ApiException('not_found', 'مفيش مستخدم بالبيانات دي.', 404); }
        if ($t['role'] !== 'user') { throw new ApiException('validation', 'الحساب ده إداري بالفعل (عدّل صلاحياته من الجدول).', 422); }
        $roleId = self::roleId($role);
        Db::tx(function () use ($role, $roleId, $t, $perms, $me) {
            if ($role === 'owner') {
                Db::one("SELECT id FROM roles WHERE code = 'owner' FOR UPDATE");
                if (self::ownerCount() >= self::MAX_OWNERS) { throw new ApiException('limit', 'وصلت للحد الأقصى: ' . self::MAX_OWNERS . ' حسابات مالك.', 409); }
            }
            Db::run('UPDATE users SET role_id = ? WHERE id = ?', [$roleId, $t['id']]);
            self::setPermRows((int)$t['id'], $perms, (int)$me['id']);
        });
        Audit::event($me, 'staff_appoint', $role === 'owner' ? 'critical' : 'warning', 'قام ' . Audit::who($me) . ' بتعيين ' . $t['name'] . ' كـ' . ($role === 'owner' ? 'مالك' : 'أدمن'), ['target' => $t['uuid'], 'perms' => $perms]);
        if ($t['email']) { Mailer::notice((string)$t['email'], (string)$t['name'], 'تم تعيينك في فريق الإدارة', 'تم تعيينك ' . ($role === 'owner' ? 'مالكًا' : 'أدمن') . ' في Port Said Hub. تقدر تدخل على لوحة التحكم من الموقع.'); }
        Http::ok(['id' => $t['uuid']], 201);
    }

    public static function setPerms(): void
    {
        $me = Auth::needOwner();
        $b = Http::body();
        $t = Admin::target(Validator::uuid($b['id'] ?? null));
        if ($t['role'] === 'owner') { throw new ApiException('validation', 'المالك يملك كل الصلاحيات دائمًا.', 422); }
        if ($t['role'] !== 'admin') { throw new ApiException('validation', 'الحساب ده مش أدمن.', 422); }
        $new = Perms::normalize($b['perms'] ?? []);
        $old = array_column(Db::all('SELECT perm FROM admin_permissions WHERE user_id = ?', [$t['id']]), 'perm');
        Db::tx(function () use ($t, $new, $me) { self::setPermRows((int)$t['id'], $new, (int)$me['id']); });
        $add = array_values(array_diff($new, $old));
        $rem = array_values(array_diff($old, $new));
        Audit::event($me, 'perms_change', 'warning', 'قام ' . Audit::who($me) . ' بتعديل صلاحيات الأدمن ' . $t['name']
            . ($add ? ' — أضاف: ' . implode('، ', array_map([Perms::class, 'label'], $add)) : '')
            . ($rem ? ' — سحب: ' . implode('، ', array_map([Perms::class, 'label'], $rem)) : ''), ['target' => $t['uuid'], 'added' => $add, 'removed' => $rem]);
        if ($t['email'] && ($add || $rem)) { Mailer::notice((string)$t['email'], (string)$t['name'], 'تم تعديل صلاحياتك', "تم تعديل صلاحياتك في لوحة التحكم.\nصلاحياتك الحالية: " . ($new ? implode('، ', array_map([Perms::class, 'label'], $new)) : 'لا يوجد')); }
        Http::ok(['perms' => $new]);
    }

    /** إزالة أدمن: بيرجع مستخدم عادي (حسابه ونقاطه تفضل) وتتشال كل صلاحياته. */
    public static function remove(): void
    {
        $me = Auth::needOwner();
        $b = Http::body();
        $t = Admin::target(Validator::uuid($b['id'] ?? null));
        if ($t['role'] === 'owner') { throw new ApiException('forbidden', 'مينفعش إزالة حساب مالك من اللوحة (لحماية النظام).', 403); }
        if ($t['role'] !== 'admin') { throw new ApiException('validation', 'الحساب ده مش أدمن.', 422); }
        $roleId = self::roleId('user');
        Db::tx(function () use ($t, $roleId) {
            Db::run('UPDATE users SET role_id = ? WHERE id = ?', [$roleId, $t['id']]);
            Db::run('DELETE FROM admin_permissions WHERE user_id = ?', [$t['id']]);
        });
        Auth::forgetPerms();
        Audit::event($me, 'staff_remove', 'warning', 'قام ' . Audit::who($me) . ' بإزالة ' . $t['name'] . ' من الأدمن (رجع مستخدم عادي)', ['target' => $t['uuid']]);
        if ($t['email']) { Mailer::notice((string)$t['email'], (string)$t['name'], 'تم إنهاء صلاحيات الإدارة', 'تم إنهاء صلاحياتك الإدارية في Port Said Hub. حسابك كمستخدم عادي يعمل كما هو.'); }
        Http::ok(['removed' => true]);
    }

    /** تعطيل/تفعيل حساب أدمن مع السبب (المالك لا يُعطَّل). */
    public static function status(): void
    {
        $me = Auth::needOwner();
        $b = Http::body();
        $t = Admin::target(Validator::uuid($b['id'] ?? null));
        if ($t['role'] !== 'admin') { throw new ApiException('forbidden', 'مينفعش تعطيل حساب مالك.', 403); }
        $st = Validator::enum($b['status'] ?? null, ['active', 'suspended'], 'الحالة');
        Admin::applyStatus($me, $t, $st, isset($b['reason']) ? (string)$b['reason'] : null);
        Http::ok(['status' => $st]);
    }
}
