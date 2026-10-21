<?php
declare(strict_types=1);
namespace App;

final class Catalog
{
    public static function meta(): void
    {
        Http::ok([
            'governorates' => Db::all('SELECT name_ar AS name FROM governorates ORDER BY id'),
            'user_types'   => Db::all('SELECT code, name_ar AS name FROM user_types ORDER BY id'),
            'institutions' => Db::all('SELECT name_ar AS name, kind, parent_ar AS parent FROM institutions WHERE is_active = 1 ORDER BY sort_order, id'),
            'categories'   => Db::all('SELECT code, name_ar AS name, icon FROM categories WHERE is_active = 1 ORDER BY sort_order'),
        ]);
    }

    /** تسجيل دخول المستخدم على قسم أو مكان (عدّاد يومي بدون أي بيانات شخصية). */
    public static function track(): void
    {
        $u = Auth::need();
        RateLimiter::hit('track:' . $u['id'], 400, 3600);
        $b = Http::body();
        $kind = Validator::enum($b['kind'] ?? null, ['section', 'place'], 'النوع');
        if ($kind === 'section') {
            $ref = Validator::enum($b['ref'] ?? null, array_merge(['explore', 'bus'], array_column(Db::all('SELECT code FROM categories'), 'code')), 'القسم');
        } else {
            $ref = Validator::uuid($b['ref'] ?? null);
            if (!Db::one('SELECT 1 AS x FROM places WHERE uuid = ? AND deleted_at IS NULL', [$ref])) { Http::ok(['tracked' => false]); return; }
        }
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Cairo')))->format('Y-m-d');
        Db::run('INSERT INTO usage_hits (day, kind, ref, hits) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$day, $kind, $ref]);
        Http::ok(['tracked' => true]);
    }

    /** استكشف بورسعيد: صور وفيديوهات (ملفاتها في assets/explore). */
    public static function explore(): void
    {
        $community = [];
        $mine = [];
        try {   // لو جدول صور الزوار لسه ما اتعملش، باقي الصفحة تفضل شغالة
            $th = self::hasImg() ? ', th_b64' : '';
            $community = Db::all("SELECT uuid AS id, file_name AS f, caption AS t$th FROM explore_uploads WHERE status = 'approved' ORDER BY id DESC LIMIT 36");
            if ($u = Auth::user()) {
                $mine = Db::all('SELECT uuid AS id, file_name AS f, caption AS t, status AS s' . $th . ' FROM explore_uploads WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$u['id']]);
            }
            $fix = static function (array $rows): array {
                foreach ($rows as $i => $x) { $rows[$i]['th'] = self::dataUri($x['th_b64'] ?? null); unset($rows[$i]['th_b64']); }
                return $rows;
            };
            $community = $fix($community);
            $mine = $fix($mine);
        } catch (\Throwable $e) {
            error_log('[explore] ' . $e->getMessage());
        }
        $items = Db::all("SELECT uuid AS id, kind, title_ar AS t, description_ar AS d, file_name AS f, (img_b64 IS NOT NULL) AS has_img, LENGTH(img_b64) AS iv FROM explore_items WHERE is_active = 1 ORDER BY sort_order, id");
        foreach ($items as &$it) { $it['has_img'] = (bool)$it['has_img']; $it['iv'] = (int)$it['iv']; }
        unset($it);
        Http::ok([
            'items' => $items,
            'community' => $community,
            'mine' => $mine,
        ]);
    }

    /** صورة عنصر "استكشف بورسعيد" (مرفوعة فعليًا من الأدمن ومخزّنة في القاعدة). */
    public static function itemImg(): void
    {
        $id = Validator::uuid($_GET['id'] ?? null);
        $row = Db::one("SELECT img_b64 FROM explore_items WHERE uuid = ? AND is_active = 1", [$id]);
        $bin = $row ? base64_decode((string)$row['img_b64'], true) : false;
        if (!$row || $bin === false || $bin === '') { throw new ApiException('not_found', 'Not found', 404); }
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . (string)strlen($bin));
        echo $bin;
        exit;
    }

    /** يضيف عمود img_b64 تلقائيًا (مرة واحدة) عشان الصور تتخزن في القاعدة بدل الفولدر. */
    public static function hasImg(): bool
    {
        static $has = null;
        if ($has !== null) { return $has; }
        try {
            $have = array_column(Db::all("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'explore_uploads' AND COLUMN_NAME IN ('img_b64','th_b64')"), 'c');
            if (!in_array('img_b64', $have, true)) { Db::run('ALTER TABLE explore_uploads ADD COLUMN img_b64 MEDIUMTEXT NULL AFTER file_name'); }
            if (!in_array('th_b64', $have, true)) { Db::run('ALTER TABLE explore_uploads ADD COLUMN th_b64 MEDIUMTEXT NULL AFTER file_name'); }
            $has = true;
        } catch (\Throwable $e) { error_log('[img_b64] ' . $e->getMessage()); $has = false; }
        return $has;
    }

    private static function dataUri(?string $b64): ?string { return ($b64 !== null && $b64 !== '') ? 'data:image/jpeg;base64,' . $b64 : null; }

    /** الصورة الكاملة كـ data URI داخل JSON (بتتطلب عند فتح الصورة بس). */
    public static function photo(): void
    {
        $id = Validator::uuid($_GET['id'] ?? null);
        if (!self::hasImg()) { throw new ApiException('not_found', 'Not found', 404); }
        $row = Db::one('SELECT user_id, status, img_b64 FROM explore_uploads WHERE uuid = ?', [$id]);
        $u = Auth::user();
        $ok = $row && ($row['status'] === 'approved' || ($u && ((int)$u['id'] === (int)$row['user_id'] || Auth::can('uploads.review', $u))));
        if (!$ok || empty($row['img_b64'])) { throw new ApiException('not_found', 'الصورة مش موجودة.', 404); }
        Http::ok(['src' => self::dataUri($row['img_b64'])]);
    }

    /** يعرض صورة زائر عبر PHP: المقبولة للجميع، وغير المقبولة لصاحبها أو لمراجع الصور فقط. */
    public static function img(): void
    {
        $f = (string)($_GET['f'] ?? '');
        if (!preg_match('~^u_[a-f0-9]{24}\.jpg$~', $f)) { throw new ApiException('not_found', 'Not found', 404); }
        $row = Db::one('SELECT user_id, status' . (self::hasImg() ? ', img_b64' : '') . ' FROM explore_uploads WHERE file_name = ?', [$f]);
        $u = Auth::user();
        $ok = $row && ($row['status'] === 'approved' || ($u && ((int)$u['id'] === (int)$row['user_id'] || Auth::can('uploads.review', $u))));
        $p = dirname(__DIR__) . '/assets/explore/users/' . $f;
        $bin = ($ok && !empty($row['img_b64'])) ? base64_decode((string)$row['img_b64'], true) : (($ok && is_file($p)) ? file_get_contents($p) : false);
        if (!$ok || $bin === false || $bin === '') { throw new ApiException('not_found', 'Not found', 404); }
        header('Content-Type: image/jpeg');
        header('Cache-Control: ' . ($row['status'] === 'approved' ? 'public, max-age=86400' : 'private, max-age=300'));
        header('Content-Length: ' . (string)strlen($bin));
        echo $bin;
        exit;
    }

    /** يمسح ملف صورة زائر من الفولدر (الاسم لازم يطابق الشكل اللي السيرفر بيولّده). */
    public static function dropUploadFile(string $name): void
    {
        if (preg_match('~^u_[a-f0-9]{24}\\.jpg$~', $name)) { @unlink(dirname(__DIR__) . '/assets/explore/users/' . $name); }
    }

    /** رفع صورة من زائر: بنتأكد إنها صورة حقيقية ونعيد ترميزها JPEG (بيشيل أي بيانات مخفية وGPS) وتستنى موافقة الأدمن. */
    public static function uploadPhoto(): void
    {
        $u = Auth::need();
        RateLimiter::hit('upl:' . $u['id'], 10, 86400);
        $pend = Db::one("SELECT COUNT(*) AS n FROM explore_uploads WHERE user_id = ? AND status = 'pending'", [$u['id']]);
        if ((int)($pend['n'] ?? 0) >= 10) { throw new ApiException('too_many', 'عندك صور كتير لسه بتتراجع. استنى لحد ما تتراجع وبعدين ارفع تاني.', 429); }
        $b = Http::body(2500000);
        $cap = (isset($b['caption']) && trim((string)$b['caption']) !== '') ? Validator::str($b['caption'], 2, 100, 'التعليق') : null;
        $raw = base64_decode((string)($b['image'] ?? ''), true);
        if ($raw === false || strlen($raw) < 1000 || strlen($raw) > 2000000) { throw new ApiException('validation', 'الصورة مش مقبولة (حجمها أو صيغتها).', 422); }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) { throw new ApiException('unsupported', 'رفع الصور مش متاح على السيرفر ده حاليًا.', 501); }
        $info = @getimagesizefromstring($raw);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) { throw new ApiException('validation', 'اختار صورة JPG أو PNG أو WebP.', 422); }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w < 200 || $h < 200 || $w > 4000 || $h > 4000 || $w * $h > 12000000) { throw new ApiException('validation', 'مقاس الصورة مش مناسب (أقل حاجة 200 بكسل وأقصى 4000).', 422); }
        $inDb = self::hasImg();
        $src = @imagecreatefromstring($raw);
        if (!$src) { throw new ApiException('validation', 'مقدرتش أقرا الصورة دي. جرّب صورة تانية.', 422); }
        $k = min(1.0, 1280 / max($w, $h));
        $W = max(1, (int)round($w * $k));
        $H = max(1, (int)round($h * $k));
        $dst = imagecreatetruecolor($W, $H);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $W, $H, $w, $h);
        ob_start();
        imagejpeg($dst, null, 82);
        $jpg = (string)ob_get_clean();
        $th = '';
        if ($inDb) {
            $tk = min(1.0, 480 / max($w, $h)); $tw = max(1, (int)round($w * $tk)); $tH = max(1, (int)round($h * $tk));
            $tm = imagecreatetruecolor($tw, $tH); imagefill($tm, 0, 0, imagecolorallocate($tm, 255, 255, 255));
            imagecopyresampled($tm, $src, 0, 0, 0, 0, $tw, $tH, $w, $h);
            ob_start(); imagejpeg($tm, null, 70); $th = base64_encode((string)ob_get_clean()); imagedestroy($tm);
        }
        imagedestroy($src);
        imagedestroy($dst);
        if ($jpg === '') { throw new ApiException('server', 'حصلت مشكلة في معالجة الصورة.', 500); }
        $dir = dirname(__DIR__) . '/assets/explore/users';
        if (!$inDb && !is_dir($dir) && !@mkdir($dir, 0755, true)) { throw new ApiException('server', 'مقدرتش أحفظ الصورة (صلاحيات الفولدر).', 500); }
        $name = 'u_' . bin2hex(random_bytes(12)) . '.jpg';
        if (!$inDb && @file_put_contents($dir . '/' . $name, $jpg, LOCK_EX) === false) { throw new ApiException('server', 'مقدرتش أحفظ الصورة (صلاحيات الفولدر).', 500); }
        $status = Settings::get('explore_auto_approve', '0') === '1' ? 'approved' : 'pending';
        $id = Security::uuid();
        if ($inDb) { Db::insert('INSERT INTO explore_uploads (uuid, user_id, file_name, img_b64, th_b64, caption, status) VALUES (?,?,?,?,?,?,?)', [$id, $u['id'], $name, base64_encode($jpg), $th, $cap, $status]); }
        else { Db::insert('INSERT INTO explore_uploads (uuid, user_id, file_name, caption, status) VALUES (?,?,?,?,?)', [$id, $u['id'], $name, $cap, $status]); }
        Audit::log((int)$u['id'], 'explore.upload', 'explore_upload', null, ['uuid' => $id, 'status' => $status]);
        Http::ok(['id' => $id, 'status' => $status], 201);
    }

    /** الزائر يحذف صورته. */
    public static function deleteMyPhoto(): void
    {
        $u = Auth::need();
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $r = Db::one('SELECT id, file_name FROM explore_uploads WHERE uuid = ? AND user_id = ?', [$id, $u['id']]);
        if (!$r) { throw new ApiException('not_found', 'الصورة مش موجودة.', 404); }
        Db::run('DELETE FROM explore_uploads WHERE id = ?', [$r['id']]);
        self::dropUploadFile((string)$r['file_name']);
        Http::ok(['deleted' => true]);
    }

    public static function transport(): void
    {
        Http::ok(['lines' => Db::all('SELECT kind, name_ar AS n, details_ar AS d FROM transport_lines WHERE is_active = 1 ORDER BY sort_order, id')]);
    }

    public static function places(): void
    {
        $sql = "SELECT p.id AS pid, p.uuid AS id, c.code AS cat, p.name_ar AS n, p.area_ar AS a, p.description_ar AS ds,
                       p.latitude AS lat, p.longitude AS lon, p.visit_points AS p, p.discount_percent AS disc, p.coupon_cost_points AS cost" . (self::hasLogo() ? ', LENGTH(p.logo_b64) AS lg' : '') . "
                FROM places p JOIN categories c ON c.id = p.category_id
                WHERE p.status = 'active' AND p.deleted_at IS NULL AND c.is_active = 1";
        $args = [];
        if (isset($_GET['category'])) {
            $sql .= ' AND c.code = ?';
            $args[] = Validator::enum($_GET['category'], array_column(Db::all('SELECT code FROM categories'), 'code'), 'التصنيف');
        }
        $rows = Db::all($sql . ' ORDER BY c.sort_order, p.name_ar', $args);
        $steps = [];
        if ($rows) {
            $ids = array_column($rows, 'pid');
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT place_id, instruction_ar FROM place_routes WHERE place_id IN ($in) ORDER BY place_id, step_no", $ids) as $s) {
                $steps[$s['place_id']][] = $s['instruction_ar'];
            }
        }
        $visited = [];
        $coupons = [];
        if ($u = Auth::user()) {
            $day = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Cairo')))->format('Y-m-d');
            foreach (Db::all('SELECT place_id FROM visits WHERE user_id = ? AND visit_day = ?', [$u['id'], $day]) as $v) { $visited[$v['place_id']] = true; }
            foreach (Db::all("SELECT * FROM coupons WHERE user_id = ? AND status = 'active' AND expires_at > UTC_TIMESTAMP()", [$u['id']]) as $c) { $coupons[$c['place_id']] = Rewards::present($c); }
        }
        foreach ($rows as &$r) {
            $pid = $r['pid'];
            $r['steps'] = $steps[$pid] ?? [];
            $r['visited'] = isset($visited[$pid]);
            $r['coupon'] = $coupons[$pid] ?? null;
            $r['lat'] = (float)$r['lat'];
            $r['lon'] = (float)$r['lon'];
            $r['lg'] = (int)($r['lg'] ?? 0);
            unset($r['pid']);
        }
        unset($r);
        Http::ok(['places' => $rows]);
    }

    /** هل عمود لوجو الأماكن موجود (بعد استيراد ملف 23)؟ بيخلّي الموقع يشتغل عادي لو لسه ما اتستوردش. */
    public static function hasLogo(): bool
    {
        static $has = null;
        if ($has !== null) { return $has; }
        try { $has = (bool)Db::one("SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'places' AND COLUMN_NAME = 'logo_b64'"); }
        catch (\Throwable $e) { $has = false; }
        return $has;
    }

    public static function placeLogo(): void
    {
        $id = Validator::uuid($_GET['id'] ?? null);
        if (!self::hasLogo()) { throw new ApiException('not_found', 'Not found', 404); }
        $row = Db::one("SELECT logo_b64, status FROM places WHERE uuid = ? AND deleted_at IS NULL", [$id]);
        if ($row && $row['status'] !== 'active' && !Auth::can('places.view')) { $row = null; }
        $bin = ($row && $row['logo_b64'] !== null) ? base64_decode((string)$row['logo_b64'], true) : false;
        if ($bin === false || $bin === '') { throw new ApiException('not_found', 'Not found', 404); }
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=604800');
        header('Content-Length: ' . (string)strlen($bin));
        echo $bin;
        exit;
    }

    /** صورة من \"لمحات من بورسعيد\" (مرفوعة من الأدمن ومخزّنة في القاعدة). */
    public static function galleryImg(): void
    {
        $id = Validator::uuid($_GET['id'] ?? null);
        $row = Db::one('SELECT img_b64 FROM gallery_items WHERE uuid = ? AND is_active = 1', [$id]);
        $bin = $row ? base64_decode((string)$row['img_b64'], true) : false;
        if (!$row || $bin === false || $bin === '') { throw new ApiException('not_found', 'Not found', 404); }
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=604800');
        header('Content-Length: ' . (string)strlen($bin));
        echo $bin;
        exit;
    }

    /** صور بورسعيد: hero-*.webp للخلفية، وأي صورة في gallery/ بتظهر لوحدها. */
    public static function gallery(): void
    {
        $base = dirname(APP_ROOT) . '/assets/port-said';
        $caps = [];
        if (is_file($base . '/captions.json')) {
            $j = json_decode((string)file_get_contents($base . '/captions.json'), true);
            if (is_array($j)) { $caps = $j; }
        }
        $list = static function (string $dir, string $name) use ($base): array {
            $out = [];
            foreach (glob($base . $dir . '/' . $name . '.{webp,jpg,jpeg,png}', GLOB_BRACE) ?: [] as $f) { $out[] = basename($f); }
            sort($out);
            return $out;
        };
        $hero = [];
        foreach ($list('', 'hero-*') as $f) { $hero[] = ['src' => 'assets/port-said/' . $f]; }
        $gal = [];
        try {   // لو الجدول لسه ما اتعملش (ملف 21) نكمل بصور الفولدر
            foreach (Db::all('SELECT uuid AS id, caption AS cap, LENGTH(img_b64) AS iv FROM gallery_items WHERE is_active = 1 ORDER BY sort_order, id') as $g) {
                $gal[] = ['src' => 'api/index.php?r=gallery/img&id=' . $g['id'] . '&v=' . (int)$g['iv'], 'cap' => (string)($g['cap'] ?? '')];
            }
        } catch (\Throwable $e) {
            error_log('[gallery] ' . $e->getMessage());
        }
        if (!$gal) {
            foreach ($list('/gallery', '*') as $f) { $gal[] = ['src' => 'assets/port-said/gallery/' . $f, 'cap' => isset($caps[$f]) ? (string)$caps[$f] : '']; }
        }
        Http::ok(['hero' => $hero, 'gallery' => $gal]);
    }
}
