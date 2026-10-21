<?php
declare(strict_types=1);
namespace App;

final class Auth
{
    private static ?array $cur = null;
    private static bool $curLoaded = false;

    /** يبدأ الجلسة بإعدادات آمنة (HttpOnly + Secure + SameSite + Strict mode + مهلات). */
    public static function boot(): void
    {
        $https = Security::isHttps();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        // لو مجلد الجلسات الافتراضي للاستضافة غير قابل للكتابة نستخدم مجلد داخل storage
        $sp = session_save_path();
        if ($sp === '' || !is_dir($sp) || !is_writable($sp)) {
            $own = APP_ROOT . '/storage/sessions';
            if (!is_dir($own)) { @mkdir($own, 0700, true); }
            if (is_dir($own) && is_writable($own)) { session_save_path($own); }
        }
        session_name($https ? '__Host-psid' : 'psid');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        if (!@session_start()) { throw new ApiException('session', 'تعذّر بدء الجلسة على السيرفر.', 500); }
        $now = time();
        if (isset($_SESSION['uid'])) {
            $idle = (int)Config::get('app.session_idle', 28800);
            $abs = (int)Config::get('app.session_absolute', 604800);
            if ($now - (int)($_SESSION['last'] ?? 0) > $idle || $now - (int)($_SESSION['born'] ?? 0) > $abs) {
                self::destroy();
                session_start();
            }
        }
        $_SESSION['last'] = $now;
    }

    private static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        session_destroy();
        self::$cur = null;
        self::$curLoaded = false;
    }

    public static function user(): ?array
    {
        if (self::$curLoaded) { return self::$cur; }
        self::$curLoaded = true;
        $id = $_SESSION['uid'] ?? null;
        if (!$id) { return self::$cur = null; }
        $u = Db::one(
            "SELECT u.*, r.code AS role, ut.code AS user_type, g.name_ar AS governorate, i.name_ar AS institution FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN user_types ut ON ut.id = u.user_type_id
             LEFT JOIN governorates g ON g.id = u.governorate_id
             LEFT JOIN institutions i ON i.id = u.institution_id
             WHERE u.id = ? AND u.deleted_at IS NULL AND u.status = 'active'",
            [$id]
        );
        if (!$u) { self::destroy(); return self::$cur = null; }
        return self::$cur = $u;
    }

    /** يتطلب تسجيل دخول (ودور معين اختياريًا). */
    public static function need(array $roles = [], bool $allowPwChange = false): array
    {
        $u = self::user();
        if (!$u) { throw new ApiException('unauthorized', 'سجّل دخولك الأول.', 401); }
        if ($roles && !in_array($u['role'], $roles, true)) {
            Audit::event($u, 'access_denied', 'warning', 'حاول ' . Audit::who($u) . ' الوصول إلى مسار غير مصرح له: ' . (string)($_GET['r'] ?? ''), [], 60);
            throw new ApiException('forbidden', 'مش مسموح لك بالعملية دي.', 403);
        }
        if (!$allowPwChange && (int)$u['must_change_password'] === 1) { throw new ApiException('must_change_password', 'لازم تغيّر كلمة المرور الأول.', 403); }
        return $u;
    }

    /* ---------------- الصلاحيات ---------------- */
    private static array $permCache = [];

    public static function isStaff(array $u): bool { return in_array($u['role'], ['owner', 'admin'], true); }

    /** صلاحيات المستخدم: المالك كل الكتالوج، الأدمن من الجدول، غيرهم لا شيء. */
    public static function perms(array $u): array
    {
        if ($u['role'] === 'owner') { return Perms::codes(); }
        if ($u['role'] !== 'admin') { return []; }
        $id = (int)$u['id'];
        if (!isset(self::$permCache[$id])) {
            self::$permCache[$id] = array_column(Db::all('SELECT perm FROM admin_permissions WHERE user_id = ?', [$id]), 'perm');
        }
        return self::$permCache[$id];
    }

    public static function forgetPerms(): void { self::$permCache = []; }

    public static function can(string $perm, ?array $u = null): bool
    {
        $u = $u ?? self::user();
        return $u !== null && in_array($perm, self::perms($u), true);
    }

    /** لازم يكون مالك أو أدمن (أي صلاحية). */
    public static function needStaff(): array { return self::need(['owner', 'admin']); }

    public static function needOwner(): array { return self::need(['owner']); }

    /** لازم يكون عنده الصلاحية دي. المحاولة المرفوضة بتتسجل في سجل الموقع. */
    public static function needPerm(string $perm): array
    {
        $u = self::needStaff();
        if (!self::can($perm, $u)) {
            Audit::event($u, 'access_denied', 'warning', 'حاول ' . Audit::who($u) . ' تنفيذ عملية بدون صلاحية «' . Perms::label($perm) . '»', ['perm' => $perm], 30);
            throw new ApiException('forbidden', 'ليس لديك صلاحية: ' . Perms::label($perm), 403);
        }
        return $u;
    }

    public static function publicUser(array $u): array
    {
        return [
            'id' => $u['uuid'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'],
            'type' => $u['user_type'], 'gov' => $u['governorate'], 'gender' => $u['gender'], 'phone' => $u['phone'] ?? null, 'institution' => $u['institution'] ?? null,
            'points' => (int)$u['points_balance'], 'must_change_password' => (int)$u['must_change_password'] === 1,
            'email_verified' => !empty($u['email_verified_at']),
            'share_claimed' => (bool)Db::one("SELECT 1 AS x FROM points_ledger WHERE user_id = ? AND reason = 'share' LIMIT 1", [$u['id']]),
            'share_points' => max(0, (int)Settings::get('share_points', '40')),
            'staff' => self::isStaff($u), 'perms' => self::perms($u),
        ];
    }

    private static function startSession(int $uid): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        $_SESSION['born'] = time();
        self::$curLoaded = false;
    }

    /** تسجيل دخول مباشر على حساب موجود بالفعل (مثلًا بعد إنشاء حساب تاجر). */
    public static function startSessionFor(int $uid): void { self::startSession($uid); }

    /* ---------------- Endpoints ---------------- */

    public static function csrf(): void { Http::ok(['token' => Security::csrfToken()]); }

    public static function me(): void
    {
        $u = self::need([], true);
        Http::ok(['user' => self::publicUser($u)]);
    }

    public static function register(): void
    {
        RateLimiter::hit('reg:' . Security::ip(), 5, 3600);
        $b = Http::body(3600000);   // ممكن يبعت صورة كارنيه الطالب مع بيانات التسجيل
        $name = Validator::name($b['name'] ?? null);
        $phone = Validator::phone($b['phone'] ?? null);
        if (!isset($b['email']) || trim((string)$b['email']) === '') {
            throw new ApiException('validation', 'البريد الإلكتروني إجباري عشان توصلك رسائل الموقع.', 422);
        }
        $email = Validator::email($b['email']);   // الإيميل إجباري
        $pw = Validator::password($b['password'] ?? null, $email ?? '', $name);
        $gender = Validator::enum($b['gender'] ?? null, ['male', 'female'], 'الجنس');
        $type = Validator::enum($b['user_type'] ?? null, ['student', 'visitor', 'local'], 'النوع');
        $govId = null;
        if (isset($b['governorate']) && $b['governorate'] !== '') {
            $g = Db::one('SELECT id FROM governorates WHERE name_ar = ?', [Validator::str($b['governorate'], 2, 50, 'المحافظة')]);
            if (!$g) { throw new ApiException('validation', 'المحافظة غير صحيحة.', 422); }
            $govId = (int)$g['id'];
        }
        if ($type === 'local' && $govId === null) {   // من بورسعيد: المحافظة بورسعيد تلقائيًا
            $pg = Db::one("SELECT id FROM governorates WHERE name_ar = 'بورسعيد'");
            $govId = $pg ? (int)$pg['id'] : null;
        }
        $typeId = (int)Db::one('SELECT id FROM user_types WHERE code = ?', [$type])['id'];
        $instId = null;
        $idCardJpg = null;
        if ($type === 'student') {   // الطالب لازم يختار كلية أو مدرسة من القائمة ويرفع صورة الكارنيه
            $in = Db::one('SELECT id FROM institutions WHERE name_ar = ? AND is_active = 1', [Validator::str($b['institution'] ?? null, 2, 120, 'الكلية أو المدرسة')]);
            if (!$in) { throw new ApiException('validation', 'اختر الكلية أو المدرسة من القائمة.', 422); }
            $instId = (int)$in['id'];
            if (!isset($b['id_card']) || trim((string)$b['id_card']) === '') {
                throw new ApiException('validation', 'ارفع صورة الكارنيه عشان نفعّل خصومات الطلبة بعد المراجعة.', 422);
            }
            $idCardJpg = ImageUtil::toJpegB64((string)$b['id_card'], 1400, 80);
        }
        $roleId = (int)Db::one("SELECT id FROM roles WHERE code = 'user'")['id'];
        $needVerify = $email !== null && Settings::get('email_verify_required', '0') === '1' && Mailer::enabled();
        try {
            $id = Db::insert(
                'INSERT INTO users (uuid, name, email, phone, password_hash, gender, user_type_id, governorate_id, institution_id, role_id, last_login_at, last_login_ip)
                 VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?)',
                [Security::uuid(), $name, $email, $phone, Security::hashPassword($pw), $gender, $typeId, $govId, $instId, $roleId, Security::ipBin()]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                if (strpos($e->getMessage(), 'uq_users_phone') !== false) { throw new ApiException('phone_taken', 'رقم الهاتف ده مسجّل قبل كده.', 409); }
                throw new ApiException('email_taken', 'الإيميل ده مسجّل قبل كده.', 409);
            }
            throw $e;
        }
        Audit::log($id, 'user.register', 'user', $id);
        if ($idCardJpg !== null) {
            Db::insert('INSERT INTO id_verifications (uuid, user_id, img_b64) VALUES (?,?,?)', [Security::uuid(), $id, $idCardJpg]);
            Audit::event(['id' => $id, 'name' => $name], 'verify', 'info', $name . ' رفع صورة كارنيه الطلبة وبانتظار مراجعة الإدارة');
        }
        if ($email !== null) { Profile::issueVerification($id, $email, $name); }
        if ($needVerify) {
            Http::ok(['verify' => true, 'message' => 'تم إنشاء حسابك. بعتنا لك رابط توثيق على بريدك، اضغطه وبعدها سجّل دخولك.'], 202);
        }
        self::startSession($id);
        Http::ok(['user' => self::publicUser(self::user())], 201);
    }

    public static function login(): void
    {
        RateLimiter::hit('login-ip:' . Security::ip(), 20, 600);
        $b = Http::body();
        $raw = $b['identifier'] ?? ($b['email'] ?? '');   // رقم هاتف أو بريد إلكتروني
        $id = is_string($raw) ? trim($raw) : '';
        $pw = is_string($b['password'] ?? null) ? $b['password'] : '';
        if ($id === '' || $pw === '' || strlen($pw) > 128 || strlen($id) > 190) {
            throw new ApiException('invalid_credentials', 'البيانات أو كلمة المرور غير صحيحة.', 401);
        }
        if (strpos($id, '@') !== false) { $col = 'email'; $val = strtolower($id); }
        else { $col = 'phone'; $val = Validator::phone($id); }   // يرفض الرقم الناقص برسالة واضحة
        RateLimiter::hit('login-id:' . $val, 10, 600);
        // $col قيمة ثابتة من الكود (email أو phone) وليست من المستخدم
        $u = Db::one("SELECT u.id, u.name, u.email, u.email_verified_at, u.password_hash, u.status, u.disabled_reason, u.failed_logins, u.locked_until, u.last_login_ip, u.deleted_at, r.code AS role
                      FROM users u JOIN roles r ON r.id = u.role_id WHERE u.$col = ?", [$val]);
        $max = (int)Settings::get('max_login_failures', '5');
        $lockMin = (int)Settings::get('lockout_minutes', '15');
        // نفس زمن التنفيذ تقريبًا سواء الحساب موجود أو لا (منع User Enumeration بالتوقيت)
        $hash = $u['password_hash'] ?? Security::hashPassword('dummy-password-for-timing');
        $valid = Security::verifyPassword($pw, $hash) && $u && $u['deleted_at'] === null;
        if ($u && $u['locked_until'] !== null && strtotime($u['locked_until'] . ' UTC') > time()) {
            Audit::event($u, 'login_failed', 'warning', 'محاولة دخول على حساب ' . Audit::who($u) . ' وهو مقفول مؤقتًا بسبب محاولات فاشلة', [], 120);
            throw new ApiException('locked', 'الحساب مقفول مؤقتًا بسبب محاولات كتير. حاول بعد شوية.', 423);
        }
        if (!$valid) {
            if ($u) {
                Db::run(
                    'UPDATE users SET failed_logins = failed_logins + 1,
                     locked_until = IF(failed_logins + 1 >= ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), locked_until) WHERE id = ?',
                    [$max, $lockMin, $u['id']]
                );
                $staff = in_array($u['role'], ['owner', 'admin'], true);
                Audit::event($u, 'login_failed', $staff ? 'critical' : 'warning', 'محاولة تسجيل دخول فاشلة (كلمة مرور خاطئة) إلى حساب ' . Audit::who($u), ['attempt' => (int)$u['failed_logins'] + 1]);
                if ((int)$u['failed_logins'] + 1 >= $max) {
                    Audit::event($u, 'account_locked', 'critical', 'تم قفل حساب ' . Audit::who($u) . ' لمدة ' . $lockMin . ' دقيقة بعد ' . $max . ' محاولات فاشلة');
                }
            } else {
                Audit::event(null, 'login_failed', 'warning', 'محاولة تسجيل دخول فاشلة بمعرّف غير مسجّل: ' . mb_substr($id, 0, 3) . '***');
            }
            throw new ApiException('invalid_credentials', 'رقم الهاتف أو الإيميل أو كلمة المرور غير صحيحة.', 401);
        }
        if ($u['status'] !== 'active') {
            $why = trim((string)($u['disabled_reason'] ?? ''));
            Audit::event($u, 'login_failed', 'notice', 'محاولة دخول على حساب معطّل: ' . Audit::who($u), [], 300);
            throw new ApiException('suspended', 'الحساب معطّل.' . ($why !== '' ? ' السبب: ' . $why : '') . ' تواصل مع الإدارة.', 403);
        }
        if ($u['email'] !== null && $u['email_verified_at'] === null && Settings::get('email_verify_required', '0') === '1' && Mailer::enabled()) {
            try {
                RateLimiter::hit('vresend:' . $u['id'], 3, 3600);
                Profile::issueVerification((int)$u['id'], (string)$u['email'], (string)$u['name']);
            } catch (ApiException $e) { /* تجاوز حد إعادة الإرسال: نكمل برسالة الرفض */ }
            throw new ApiException('email_unverified', 'لازم توثّق بريدك الإلكتروني الأول. بعتنا لك رابط جديد على بريدك.', 403);
        }
        if (Security::needsRehash($u['password_hash'])) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [Security::hashPassword($pw), $u['id']]);
        }
        $prevIp = $u['last_login_ip'];
        Db::run('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?', [Security::ipBin(), $u['id']]);
        self::startSession((int)$u['id']);
        Audit::log((int)$u['id'], 'user.login', 'user', (int)$u['id']);
        // إشعار بريدي: دخول من شبكة جديدة (أو أي دخول لحسابات الإدارة) — للبريد الموثّق فقط
        $staff = in_array($u['role'], ['owner', 'admin'], true);
        if ($u['email'] !== null && $u['email_verified_at'] !== null && Settings::get('notify_login_email', '1') === '1' && ($staff || $prevIp !== Security::ipBin())) {
            Mailer::loginNotice((string)$u['email'], (string)$u['name'], Security::ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
        Http::ok(['user' => self::publicUser(self::user())]);
    }

    public static function logout(): void
    {
        $u = self::user();
        if ($u) { Audit::log((int)$u['id'], 'user.logout', 'user', (int)$u['id']); }
        self::destroy();
        Http::ok(['bye' => true]);
    }

    public static function changePassword(): void
    {
        $u = self::need([], true);
        RateLimiter::hit('chpw:' . $u['id'], 5, 600);
        $b = Http::body();
        $cur = is_string($b['current_password'] ?? null) ? $b['current_password'] : '';
        if (!Security::verifyPassword($cur, $u['password_hash'])) { throw new ApiException('invalid_credentials', 'كلمة المرور الحالية غير صحيحة.', 401); }
        $new = Validator::password($b['new_password'] ?? null, $u['email'] ?? '', $u['name']);
        if (hash_equals($cur, $new)) { throw new ApiException('validation', 'اختار كلمة مرور مختلفة عن الحالية.', 422); }
        Db::run('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [Security::hashPassword($new), $u['id']]);
        session_regenerate_id(true);
        Audit::log((int)$u['id'], 'user.password_changed', 'user', (int)$u['id']);
        if (!empty($u['email']) && !empty($u['email_verified_at'])) { Mailer::notice((string)$u['email'], (string)$u['name'], 'تم تغيير كلمة المرور', 'تم تغيير كلمة مرور حسابك الآن. لو مش انت اللي غيّرتها تواصل مع الإدارة فورًا.'); }
        Http::ok(['changed' => true]);
    }
}
