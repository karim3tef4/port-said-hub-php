<?php
declare(strict_types=1);
namespace App;

/**
 * حساب التاجر: منيو المطعم/المحل (صور) + العروض.
 * - المنيو بيتخزن على حساب التاجر نفسه، وبيظهر للعميل أول ما طلب التاجر يتوافق عليه ويتربط بمكان.
 * - كل عرض بينزل بيتحوّل لإشعار بيوصل لكل العملاء (لازم المتجر يكون متوافق عليه ومربوط بمكان).
 */
final class Shop
{
    private const MAX_MENU = 12;

    /** طلب التاجر المتوافق عليه والمربوط بمكان (أو خطأ واضح). */
    private static function liveApp(array $u): array
    {
        $a = Db::one(
            "SELECT a.id, a.place_id, a.business_name, p.name_ar AS place_name
             FROM partner_applications a JOIN places p ON p.id = a.place_id AND p.deleted_at IS NULL
             WHERE a.user_id = ? AND a.status = 'approved' AND a.place_id IS NOT NULL",
            [$u['id']]
        );
        if (!$a) {
            throw new ApiException('not_ready', 'العروض بتتفعّل بعد ما الإدارة توافق على متجرك وتربطه بمكانه في الموقع.', 409);
        }
        return $a;
    }

    /* ---------------- المنيو ---------------- */

    public static function menuList(): void
    {
        $u = Auth::need(['merchant']);
        $rows = Db::all('SELECT uuid AS id, caption AS c FROM menu_images WHERE user_id = ? ORDER BY sort_order, id', [$u['id']]);
        $live = (bool)Db::one("SELECT 1 AS x FROM partner_applications WHERE user_id = ? AND status = 'approved' AND place_id IS NOT NULL", [$u['id']]);
        Http::ok(['items' => $rows, 'max' => self::MAX_MENU, 'live' => $live]);
    }

    /** رفع صفحة منيو: بنتأكد إنها صورة حقيقية ونعيد ترميزها JPEG (بيشيل أي بيانات مخفية وGPS). */
    public static function menuAdd(): void
    {
        $u = Auth::need(['merchant']);
        RateLimiter::hit('menu:' . $u['id'], 30, 3600);
        $b = Http::body(3600000);
        $cap = (isset($b['caption']) && trim((string)$b['caption']) !== '') ? Validator::str($b['caption'], 2, 80, 'وصف الصفحة') : null;
        $cnt = (int)(Db::one('SELECT COUNT(*) AS n FROM menu_images WHERE user_id = ?', [$u['id']])['n'] ?? 0);
        if ($cnt >= self::MAX_MENU) { throw new ApiException('too_many', 'وصلت للحد الأقصى (' . self::MAX_MENU . ' صفحة). احذف صفحة قديمة وبعدين ارفع.', 409); }
        $jpgB64 = ImageUtil::toJpegB64((string)($b['image'] ?? ''), 1600, 82);
        $id = Security::uuid();
        Db::insert('INSERT INTO menu_images (uuid, user_id, caption, sort_order, img_b64) VALUES (?,?,?,?,?)', [$id, $u['id'], $cap, $cnt, $jpgB64]);
        Audit::event($u, 'menu', 'info', 'قام ' . Audit::who($u) . ' برفع صفحة منيو لمتجره', ['uuid' => $id]);
        Http::ok(['id' => $id], 201);
    }

    public static function menuDelete(): void
    {
        $u = Auth::need(['merchant']);
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $n = Db::run('DELETE FROM menu_images WHERE uuid = ? AND user_id = ?', [$id, $u['id']]);
        if ($n < 1) { throw new ApiException('not_found', 'الصفحة مش موجودة.', 404); }
        Audit::event($u, 'menu', 'info', 'قام ' . Audit::who($u) . ' بحذف صفحة منيو من متجره', ['uuid' => $id]);
        Http::ok(['deleted' => true]);
    }

    /** بيعرض صورة المنيو: للعملاء لو متجرها متوافق عليه ومربوط، ولصاحبها، ولمن يملك صلاحية الأماكن. */
    public static function menuImg(): void
    {
        $u = Auth::need();
        $id = Validator::uuid($_GET['id'] ?? null);
        $row = Db::one(
            "SELECT m.user_id, m.img_b64, (a.id IS NOT NULL) AS live
             FROM menu_images m
             LEFT JOIN partner_applications a ON a.user_id = m.user_id AND a.status = 'approved' AND a.place_id IS NOT NULL
             WHERE m.uuid = ?",
            [$id]
        );
        $ok = $row && ((int)$row['live'] === 1 || (int)$row['user_id'] === (int)$u['id'] || Auth::can('places.view', $u));
        $bin = $ok ? base64_decode((string)$row['img_b64'], true) : false;
        if (!$ok || $bin === false || $bin === '') { throw new ApiException('not_found', 'Not found', 404); }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string)strlen($bin));
        echo $bin;
        exit;
    }

    /* ---------------- العروض ---------------- */

    public static function offersList(): void
    {
        $u = Auth::need(['merchant']);
        $rows = Db::all(
            "SELECT o.uuid AS id, o.title, o.body, o.expires_at, o.created_at, (o.img_b64 IS NOT NULL) AS has_img, (o.expires_at <= UTC_TIMESTAMP()) AS expired
             FROM place_offers o WHERE o.user_id = ? AND o.status = 'active' ORDER BY o.id DESC LIMIT 30",
            [$u['id']]
        );
        foreach ($rows as &$r) { $r['expired'] = (bool)$r['expired']; $r['has_img'] = (bool)$r['has_img']; }
        unset($r);
        $live = (bool)Db::one("SELECT 1 AS x FROM partner_applications WHERE user_id = ? AND status = 'approved' AND place_id IS NOT NULL", [$u['id']]);
        Http::ok(['items' => $rows, 'live' => $live]);
    }

    /** عرض جديد: بيظهر في صفحة المكان وبيوصل كإشعار لكل العملاء. */
    public static function offerAdd(): void
    {
        $u = Auth::need(['merchant']);
        RateLimiter::hit('offer:' . $u['id'], 6, 86400);
        $app = self::liveApp($u);
        $b = Http::body(3600000);
        $title = Validator::str($b['title'] ?? null, 3, 120, 'عنوان العرض');
        $body = Validator::str($b['body'] ?? null, 5, 500, 'تفاصيل العرض');
        $days = Validator::int($b['days'] ?? 7, 1, 30, 'مدة العرض');
        $img = (isset($b['image']) && trim((string)$b['image']) !== '') ? ImageUtil::toJpegB64((string)$b['image'], 1400, 82) : null;
        $offerId = Security::uuid();
        $notifId = Security::uuid();
        $name = (string)$app['place_name'];
        Db::tx(function () use ($u, $app, $title, $body, $days, $offerId, $notifId, $name, $img) {
            $nid = Db::insert(
                'INSERT INTO notifications (uuid, kind, title, body, place_id, created_by, expires_at, created_at) VALUES (?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY), UTC_TIMESTAMP())',
                [$notifId, 'offer', $name . ' — ' . $title, $body, $app['place_id'], $u['id'], $days]
            );
            Db::insert(
                'INSERT INTO place_offers (uuid, place_id, user_id, notification_id, title, body, img_b64, expires_at) VALUES (?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY))',
                [$offerId, $app['place_id'], $u['id'], $nid, $title, $body, $img, $days]
            );
        });
        Audit::event($u, 'menu', 'notice', 'قام ' . Audit::who($u) . ' بإضافة عرض «' . $title . '» لمكان «' . $name . '» (اتبعت كإشعار لكل العملاء)', ['offer' => $offerId]);
        Http::ok(['id' => $offerId], 201);
    }

    public static function offerRemove(): void
    {
        $u = Auth::need(['merchant']);
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $o = Db::one("SELECT id, notification_id, title FROM place_offers WHERE uuid = ? AND user_id = ? AND status = 'active'", [$id, $u['id']]);
        if (!$o) { throw new ApiException('not_found', 'العرض مش موجود.', 404); }
        Db::run("UPDATE place_offers SET status = 'removed' WHERE id = ?", [$o['id']]);
        if ($o['notification_id']) { Db::run('UPDATE notifications SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$o['notification_id']]); }
        Audit::event($u, 'menu', 'info', 'قام ' . Audit::who($u) . ' بإنهاء العرض «' . $o['title'] . '»', ['offer' => $id]);
        Http::ok(['removed' => true]);
    }

    /* ---------------- للعميل: منيو وعروض المكان ---------------- */

    public static function placeExtras(): void
    {
        Auth::need();
        $uuid = Validator::uuid($_GET['place'] ?? null);
        $p = Db::one("SELECT id FROM places WHERE uuid = ? AND status = 'active' AND deleted_at IS NULL", [$uuid]);
        if (!$p) { throw new ApiException('not_found', 'المكان مش موجود.', 404); }
        $menu = Db::all(
            "SELECT m.uuid AS id, m.caption AS c FROM menu_images m
             JOIN partner_applications a ON a.user_id = m.user_id AND a.status = 'approved' AND a.place_id = ?
             ORDER BY m.sort_order, m.id",
            [$p['id']]
        );
        $offers = Db::all(
            "SELECT uuid AS id, title AS t, body AS b, expires_at AS ex, (img_b64 IS NOT NULL) AS img FROM place_offers
             WHERE place_id = ? AND status = 'active' AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 10",
            [$p['id']]
        );
        foreach ($offers as &$o) { $o['img'] = (bool)$o['img']; }
        unset($o);
        Http::ok(['menu' => $menu, 'offers' => $offers]);
    }

    /** صورة العرض: للعملاء لو العرض شغال، ولصاحبه، ولمن يملك صلاحية الأماكن. */
    public static function offerImg(): void
    {
        $u = Auth::need();
        $id = Validator::uuid($_GET['id'] ?? null);
        $row = Db::one("SELECT user_id, img_b64, (status = 'active' AND expires_at > UTC_TIMESTAMP()) AS live FROM place_offers WHERE uuid = ?", [$id]);
        $ok = $row && $row['img_b64'] !== null && ((int)$row['live'] === 1 || (int)$row['user_id'] === (int)$u['id'] || Auth::can('places.view', $u));
        $bin = $ok ? base64_decode((string)$row['img_b64'], true) : false;
        if (!$ok || $bin === false || $bin === '') { throw new ApiException('not_found', 'Not found', 404); }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string)strlen($bin));
        echo $bin;
        exit;
    }

    /** إحصائيات بسيطة للتاجر عن مكانه (بتشتغل بعد ما الإدارة تربط متجره بمكان). */
    public static function stats(): void
    {
        $u = Auth::need(['merchant']);
        $a = Db::one("SELECT place_id FROM partner_applications WHERE user_id = ? AND status = 'approved' AND place_id IS NOT NULL", [$u['id']]);
        $offers = (int)(Db::one("SELECT COUNT(*) AS n FROM place_offers WHERE user_id = ? AND status = 'active' AND expires_at > UTC_TIMESTAMP()", [$u['id']])['n'] ?? 0);
        $menu = (int)(Db::one('SELECT COUNT(*) AS n FROM menu_images WHERE user_id = ?', [$u['id']])['n'] ?? 0);
        $out = ['live' => (bool)$a, 'offers' => $offers, 'menu' => $menu, 'visits_total' => 0, 'visits_7d' => 0, 'coupons_active' => 0, 'coupons_redeemed' => 0];
        if ($a) {
            $pid = $a['place_id'];
            $out['visits_total'] = (int)(Db::one('SELECT COUNT(*) AS n FROM visits WHERE place_id = ?', [$pid])['n'] ?? 0);
            $day = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Cairo')))->modify('-6 days')->format('Y-m-d');
            $out['visits_7d'] = (int)(Db::one('SELECT COUNT(*) AS n FROM visits WHERE place_id = ? AND visit_day >= ?', [$pid, $day])['n'] ?? 0);
            $out['coupons_active'] = (int)(Db::one("SELECT COUNT(*) AS n FROM coupons WHERE place_id = ? AND status = 'active' AND expires_at > UTC_TIMESTAMP()", [$pid])['n'] ?? 0);
            $out['coupons_redeemed'] = (int)(Db::one("SELECT COUNT(*) AS n FROM coupons WHERE place_id = ? AND status = 'redeemed'", [$pid])['n'] ?? 0);
        }
        Http::ok($out);
    }
}
