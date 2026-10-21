<?php
declare(strict_types=1);
namespace App;

/** "سجل متجرك": حساب تاجر خاص + طلب انضمام يراجعه الأدمن ويُربط بمكان فعلي. */
final class Partners
{
    private static function present(array $a): array
    {
        return [
            'id' => $a['uuid'], 'business_name' => $a['business_name'], 'category' => $a['category'] ?? null,
            'business_type_other' => $a['business_type_other'] ?? null,
            'area' => $a['area_ar'], 'phone' => $a['phone'],
            'discount' => (int)$a['proposed_discount_percent'], 'commission' => (float)$a['proposed_commission_amount'],
            'notes' => $a['promo_notes'], 'tax_number' => $a['tax_number'] ?? null,
            'has_registry' => !empty($a['commercial_registry_img']),
            'status' => $a['status'], 'admin_note' => $a['admin_note'],
            'place_linked' => !empty($a['place_id']), 'created_at' => $a['created_at'],
            'lat' => isset($a['latitude']) ? (float)$a['latitude'] : null, 'lon' => isset($a['longitude']) ? (float)$a['longitude'] : null,
        ];
    }

    /** تسجيل متجر جديد: ينشئ حساب تاجر (role=merchant) + طلب انضمام بانتظار المراجعة. */
    public static function apply(): void
    {
        RateLimiter::hit('partner-apply:' . Security::ip(), 5, 3600);
        $b = Http::body(6200000);   // بيبعت صورة السجل التجاري مع بيانات التسجيل
        $name = Validator::name($b['name'] ?? null);
        $email = Validator::email($b['email'] ?? null);
        $phone = Validator::phone($b['phone'] ?? null);
        $pw = Validator::password($b['password'] ?? null, $email, $name);
        $business = Validator::str($b['business_name'] ?? null, 2, 150, 'اسم المتجر');
        $area = Validator::str($b['area'] ?? null, 2, 100, 'المنطقة');
        $discount = Validator::int($b['discount'] ?? null, 0, 100, 'نسبة الخصم');
        $commission = Validator::float($b['commission'] ?? null, 0, 10000, true, 'العمولة لفريق Alpha');
        $notes = (isset($b['notes']) && trim((string)$b['notes']) !== '') ? Validator::str($b['notes'], 2, 500, 'الأكواد أو الملاحظات') : null;
        $catCode = isset($b['category']) ? trim((string)$b['category']) : '';
        $catId = null;
        $otherType = null;
        if ($catCode === 'other' || $catCode === '') {
            $otherType = Validator::str($b['business_type_other'] ?? null, 2, 100, 'نوع النشاط');
        } else {
            $c = Db::one('SELECT id FROM categories WHERE code = ?', [Validator::str($catCode, 2, 30, 'التصنيف')]);
            if (!$c) { throw new ApiException('validation', 'التصنيف غير صحيح.', 422); }
            $catId = (int)$c['id'];
        }
        $taxNumber = Validator::str($b['tax_number'] ?? null, 3, 30, 'الرقم الضريبي');
        if (!preg_match('/^[0-9A-Za-z\/-]{3,30}$/u', $taxNumber)) { throw new ApiException('validation', 'الرقم الضريبي غير صحيح.', 422); }
        if (!isset($b['commercial_registry']) || trim((string)$b['commercial_registry']) === '') {
            throw new ApiException('validation', 'ارفع صورة السجل التجاري عشان نتأكد إن المتجر حقيقي.', 422);
        }
        $registryJpg = ImageUtil::toJpegB64((string)$b['commercial_registry'], 1600, 82);
        $roleRow = Db::one("SELECT id FROM roles WHERE code = 'merchant'");
        if (!$roleRow) { throw new ApiException('server', 'الأدوار غير مجهّزة. شغّل ملف 02_seed.sql.', 500); }
        $roleId = (int)$roleRow['id'];
        try {
            [$uid, $appUuid] = Db::tx(function () use ($name, $email, $phone, $pw, $roleId, $business, $catId, $otherType, $area, $discount, $commission, $notes, $taxNumber, $registryJpg) {
                $uid = Db::insert(
                    'INSERT INTO users (uuid, name, email, phone, password_hash, role_id, last_login_at, last_login_ip) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),?)',
                    [Security::uuid(), $name, $email, $phone, Security::hashPassword($pw), $roleId, Security::ipBin()]
                );
                $appUuid = Security::uuid();
                Db::insert(
                    'INSERT INTO partner_applications (uuid, user_id, business_name, category_id, business_type_other, area_ar, phone, proposed_discount_percent, proposed_commission_amount, promo_notes, tax_number, commercial_registry_img) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$appUuid, $uid, $business, $catId, $otherType, $area, $phone, $discount, $commission, $notes, $taxNumber, $registryJpg]
                );
                return [$uid, $appUuid];
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                if (strpos($e->getMessage(), 'uq_users_phone') !== false) { throw new ApiException('phone_taken', 'رقم الهاتف ده مسجّل قبل كده.', 409); }
                throw new ApiException('email_taken', 'الإيميل ده مسجّل قبل كده.', 409);
            }
            throw $e;
        }
        Audit::log($uid, 'partner.apply', 'partner_application', $uid);
        Mailer::notice($email, $name, 'استلمنا طلب انضمامك كتاجر', 'شكرًا لتسجيل «' . $business . '» في Port Said Hub.' . "\n" . 'هنراجع طلبك ونوصل لك خبر الموافقة قريبًا على نفس البريد.');
        Auth::startSessionFor($uid);
        Http::ok(['user' => Auth::publicUser(Auth::user()), 'application' => self::present(Db::one('SELECT * FROM partner_applications WHERE user_id = ?', [$uid]))], 201);
    }

    private static function myApp(array $u): array
    {
        $a = Db::one('SELECT a.*, c.code AS category FROM partner_applications a LEFT JOIN categories c ON c.id = a.category_id WHERE a.user_id = ?', [$u['id']]);
        if (!$a) { throw new ApiException('not_found', 'مفيش طلب انضمام على حسابك.', 404); }
        return $a;
    }

    public static function me(): void
    {
        $u = Auth::need(['merchant']);
        Http::ok(['application' => self::present(self::myApp($u))]);
    }

    /** التاجر بيعدّل عرضه (الخصم/العمولة/الأكواد). أي تعديل بيرجّع الطلب "قيد المراجعة" تاني لو كان متفق عليه قبل كده. */
    public static function updateOffer(): void
    {
        $u = Auth::need(['merchant']);
        RateLimiter::hit('partner-upd:' . $u['id'], 20, 3600);
        self::myApp($u);   // يتأكد إن عنده طلب أصلًا
        $b = Http::body();
        $business = Validator::str($b['business_name'] ?? null, 2, 150, 'اسم المتجر');
        $area = Validator::str($b['area'] ?? null, 2, 100, 'المنطقة');
        $discount = Validator::int($b['discount'] ?? null, 0, 100, 'نسبة الخصم');
        $commission = Validator::float($b['commission'] ?? null, 0, 10000, true, 'العمولة لفريق Alpha');
        $notes = (isset($b['notes']) && trim((string)$b['notes']) !== '') ? Validator::str($b['notes'], 2, 500, 'الأكواد أو الملاحظات') : null;
        $loc = '';
        $locArgs = [];
        if (isset($b['lat'], $b['lon']) && $b['lat'] !== '' && $b['lon'] !== '') {
            if (!is_numeric($b['lat']) || !is_numeric($b['lon'])) { throw new ApiException('validation', 'الموقع غير صحيح.', 422); }
            $la = round((float)$b['lat'], 6);
            $lo = round((float)$b['lon'], 6);
            if ($la < 30.9 || $la > 31.5 || $lo < 32.0 || $lo > 32.7) { throw new ApiException('validation', 'الموقع ده خارج بورسعيد. اضغط الزر وإنت في المحل.', 422); }
            $loc = ', latitude=?, longitude=?';
            $locArgs = [$la, $lo];
        }
        Db::run(
            "UPDATE partner_applications SET business_name=?, area_ar=?, proposed_discount_percent=?, proposed_commission_amount=?, promo_notes=?$loc, status='pending', admin_note=NULL WHERE user_id=?",
            array_merge([$business, $area, $discount, $commission, $notes], $locArgs, [$u['id']])
        );
        if ($locArgs) { Db::run('UPDATE places SET latitude = ?, longitude = ? WHERE id = (SELECT place_id FROM partner_applications WHERE user_id = ?) AND deleted_at IS NULL', [$locArgs[0], $locArgs[1], $u['id']]); }
        Audit::event($u, 'partner.update', 'info', 'قام ' . Audit::who($u) . ' بتعديل عرض متجره «' . $business . '»');
        Http::ok(['application' => self::present(self::myApp($u))]);
    }

    /** عدد الطلبات قيد المراجعة — تُستخدم لإظهار نقطة تنبيه بجانب تبويب "التجار". */
    public static function adminPendingCount(): void
    {
        Auth::needPerm('partners.manage');
        Http::ok(['count' => (int)Db::one("SELECT COUNT(*) AS n FROM partner_applications WHERE status = 'pending'")['n']]);
    }

    public static function adminList(): void
    {
        Auth::needPerm('partners.manage');
        $rows = Db::all(
            "SELECT a.uuid AS id, a.business_name, c.code AS category, a.business_type_other, a.area_ar AS area, a.phone,
                    a.proposed_discount_percent AS discount, a.proposed_commission_amount AS commission, a.promo_notes AS notes,
                    a.tax_number, (a.commercial_registry_img IS NOT NULL) AS has_registry,
                    a.status, a.admin_note, a.created_at,
                    u.uuid AS user_id, u.name AS owner_name, u.email AS owner_email, u.status AS account_status,
                    p.uuid AS place_id, p.name_ar AS place_name
             FROM partner_applications a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN categories c ON c.id = a.category_id
             LEFT JOIN places p ON p.id = a.place_id
             ORDER BY (a.status = 'pending') DESC, a.id DESC"
        );
        foreach ($rows as &$r) { $r['has_registry'] = (bool)$r['has_registry']; }
        unset($r);
        Http::ok(['applications' => $rows]);
    }

    private static function app(string $uuid): array
    {
        $a = Db::one('SELECT * FROM partner_applications WHERE uuid = ?', [$uuid]);
        if (!$a) { throw new ApiException('not_found', 'الطلب مش موجود.', 404); }
        return $a;
    }

    /** بيضيف المتجر المقبول كمكان جديد ونشط في الموقع فورًا (من غير ما الأدمن يدخّله يدوي). الموقع من بيانات التاجر أو وسط بورسعيد لحد ما يتحدد. */
    private static function createPlace(array $me, array $a): string
    {
        $cat = !empty($a['category_id']) ? Db::one('SELECT id FROM categories WHERE id = ?', [$a['category_id']]) : null;
        if (!$cat) { $cat = Db::one("SELECT id FROM categories WHERE code = 'food'") ?: Db::one('SELECT id FROM categories ORDER BY sort_order LIMIT 1'); }
        $hasLoc = isset($a['latitude']) && $a['latitude'] !== null && $a['longitude'] !== null;
        $id = Security::uuid();
        Db::insert(
            "INSERT INTO places (uuid, category_id, name_ar, area_ar, latitude, longitude, discount_percent, commission_amount, status, is_demo) VALUES (?,?,?,?,?,?,?,?,'active',0)",
            [$id, $cat['id'], $a['business_name'], $a['area_ar'], $hasLoc ? $a['latitude'] : 31.2653, $hasLoc ? $a['longitude'] : 32.3019, $a['proposed_discount_percent'], $a['proposed_commission_amount']]
        );
        Audit::event($me, 'place_add', 'info', 'اتضاف المكان «' . $a['business_name'] . '» تلقائيًا بعد الموافقة على التاجر' . ($hasLoc ? '' : ' (الموقع مؤقت: وسط بورسعيد)'), ['uuid' => $id]);
        return $id;
    }

    /** ربط (أو إعادة ربط) طلب تم قبوله بمكان فعلي: بينسخ الخصم والعمولة على المكان ويضيف حساب التاجر كموظف عليه. */
    private static function link(array $me, array $a, string $placeUuid): array
    {
        $p = Db::one('SELECT id, uuid, name_ar FROM places WHERE uuid = ? AND deleted_at IS NULL', [$placeUuid]);
        if (!$p) { throw new ApiException('not_found', 'المكان مش موجود.', 404); }
        Db::tx(function () use ($a, $p) {
            Db::run('UPDATE places SET discount_percent = ?, commission_amount = ? WHERE id = ?', [$a['proposed_discount_percent'], $a['proposed_commission_amount'], $p['id']]);
            Db::run('INSERT IGNORE INTO place_staff (user_id, place_id) VALUES (?, ?)', [$a['user_id'], $p['id']]);
            Db::run('UPDATE partner_applications SET place_id = ? WHERE id = ?', [$p['id'], $a['id']]);
        });
        Audit::event($me, 'partner.link', 'notice', 'قام ' . Audit::who($me) . ' بربط التاجر «' . $a['business_name'] . '» بمكان «' . $p['name_ar'] . '»', ['application' => $a['uuid'], 'place' => $p['uuid']]);
        return $p;
    }

    /** صورة السجل التجاري — للأدمن صاحب صلاحية partners.manage بس. */
    public static function adminRegistryImg(): void
    {
        Auth::needPerm('partners.manage');
        $id = Validator::uuid($_GET['id'] ?? null);
        $row = Db::one('SELECT commercial_registry_img FROM partner_applications WHERE uuid = ?', [$id]);
        $bin = $row ? base64_decode((string)$row['commercial_registry_img'], true) : false;
        if (!$row || $bin === false || $bin === '') { throw new ApiException('not_found', 'Not found', 404); }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string)strlen($bin));
        echo $bin;
        exit;
    }

    public static function adminLink(): void
    {
        $me = Auth::needPerm('partners.manage');
        $b = Http::body();
        $a = self::app(Validator::uuid($b['id'] ?? null));
        if ($a['status'] !== 'approved') { throw new ApiException('validation', 'لازم توافق على الطلب الأول قبل الربط بمكان.', 422); }
        $created = false;
        if (empty($b['place_id'])) { $puuid = self::createPlace($me, $a); $created = true; } else { $puuid = Validator::uuid($b['place_id']); }
        self::link($me, $a, $puuid);
        Http::ok(['application' => self::present(self::app($a['uuid'])), 'place_created' => $created]);
    }

    /** موافقة أو رفض الطلب، مع إمكانية تعديل الأدمن للخصم/العمولة قبل الموافقة والربط بمكان موجود في نفس الخطوة. */
    public static function adminReview(): void
    {
        $me = Auth::needPerm('partners.manage');
        $b = Http::body();
        $a = self::app(Validator::uuid($b['id'] ?? null));
        $status = Validator::enum($b['status'] ?? null, ['approved', 'rejected'], 'الحالة');
        $note = (isset($b['note']) && trim((string)$b['note']) !== '') ? Validator::str($b['note'], 2, 500, 'الملاحظة') : null;
        if ($status === 'rejected' && $note === null) { throw new ApiException('validation', 'اكتب سبب الرفض.', 422); }
        $owner = Db::one('SELECT name, email FROM users WHERE id = ?', [$a['user_id']]);
        if ($status === 'approved' && isset($b['discount']) && isset($b['commission'])) {
            $discount = Validator::int($b['discount'], 0, 100, 'نسبة الخصم');
            $commission = Validator::float($b['commission'], 0, 10000, true, 'العمولة لفريق Alpha');
            Db::run('UPDATE partner_applications SET proposed_discount_percent = ?, proposed_commission_amount = ? WHERE id = ?', [$discount, $commission, $a['id']]);
        }
        Db::run('UPDATE partner_applications SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $note, $me['id'], $a['id']]);
        Audit::event($me, 'partner.review', $status === 'approved' ? 'notice' : 'warning', 'قام ' . Audit::who($me) . ($status === 'approved' ? ' بالموافقة على ' : ' برفض ') . 'طلب التاجر «' . $a['business_name'] . '»' . ($note ? ' — ' . $note : ''), ['application' => $a['uuid']]);
        $created = false;
        if ($status === 'approved') {
            $fresh = Db::one('SELECT * FROM partner_applications WHERE id = ?', [$a['id']]);
            if (!empty($b['place_id'])) { $puuid = Validator::uuid($b['place_id']); }
            elseif (!empty($fresh['place_id'])) { $puuid = (string)Db::one('SELECT uuid FROM places WHERE id = ?', [$fresh['place_id']])['uuid']; }
            else { $puuid = self::createPlace($me, $fresh); $created = true; }
            self::link($me, $fresh, $puuid);
        }
        if ($status === 'approved') {
            Db::run('UPDATE users SET verified_at = UTC_TIMESTAMP() WHERE id = ? AND verified_at IS NULL', [$a['user_id']]);
            Notifications::sendTo($a['user_id'], 'تم التحقق منك ✅', 'مبروك، تم التحقق من متجرك «' . $a['business_name'] . '» والتأكد من بياناته. مرحبًا بيك في عائلة شركاء Port Said Hub!');
        }
        if ($owner && $owner['email']) {
            $msg = $status === 'approved'
                ? 'مبروك! طلب انضمام متجرك «' . $a['business_name'] . '» اتوافق عليه. هتقدر تتابع كوبونات عملائك من حسابك.'
                : 'للأسف طلب انضمام متجرك «' . $a['business_name'] . '» اتأجل حاليًا.' . ($note ? "\nالسبب: " . $note : '');
            Mailer::notice((string)$owner['email'], (string)$owner['name'], $status === 'approved' ? 'تمت الموافقة على طلبك' : 'بخصوص طلب انضمامك', $msg);
        }
        Http::ok(['application' => self::present(self::app($a['uuid'])), 'place_created' => $created]);
    }
}
