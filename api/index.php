<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\{Feedback, Reviews, Http, Auth, Security, ApiException, Catalog, Rewards, Admin, Staff, Logs, Profile, Audit, Partners, Assistant, Notifications, Shop, Verify};

Http::secureHeaders();

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $route  = (string)($_GET['r'] ?? '');
    if (!preg_match('~^[a-z0-9/-]{1,60}$~', $route)) { throw new ApiException('not_found', 'Not found', 404); }

    $routes = [
        'GET csrf'              => [Auth::class, 'csrf'],
        'GET meta'              => [Catalog::class, 'meta'],
        'GET places'            => [Catalog::class, 'places'],
        'GET transport'         => [Catalog::class, 'transport'],
        'GET gallery'           => [Catalog::class, 'gallery'],
        'GET gallery/img'       => [Catalog::class, 'galleryImg'],
        'GET explore'           => [Catalog::class, 'explore'],
        'GET explore/img'       => [Catalog::class, 'img'],
        'GET explore/item-img'  => [Catalog::class, 'itemImg'],
        'GET explore/photo'     => [Catalog::class, 'photo'],
        'POST track'            => [Catalog::class, 'track'],
        'POST explore/my-delete' => [Catalog::class, 'deleteMyPhoto'],
        'POST explore/upload'   => [Catalog::class, 'uploadPhoto'],
        'GET merchant/redemptions' => [Rewards::class, 'merchantRedemptions'],
        'GET admin/coupons'     => [Admin::class, 'couponsLog'],
        'POST admin/users/points' => [Admin::class, 'adjustPoints'],
        'POST admin/places/logo' => [Admin::class, 'placeLogo'],
        'GET place/logo'        => [Catalog::class, 'placeLogo'],
        'POST admin/feedback/delete' => [Feedback::class, 'adminDelete'],
        'POST admin/uploads/reject' => [Admin::class, 'rejectUpload'],
        'GET me'                => [Auth::class, 'me'],
        'POST auth/register'    => [Auth::class, 'register'],
        'POST auth/login'       => [Auth::class, 'login'],
        'POST auth/logout'      => [Auth::class, 'logout'],
        'POST auth/change-password' => [Auth::class, 'changePassword'],
        'POST auth/verify-email'    => [Profile::class, 'verify'],
        'POST auth/resend-verification' => [Profile::class, 'resend'],
        'POST partners/apply'        => [Partners::class, 'apply'],
        'GET partners/me'            => [Partners::class, 'me'],
        'POST partners/update'       => [Partners::class, 'updateOffer'],
        'GET admin/partners'         => [Partners::class, 'adminList'],
        'GET admin/partners/pending-count' => [Partners::class, 'adminPendingCount'],
        'POST admin/partners/review' => [Partners::class, 'adminReview'],
        'POST admin/partners/link'   => [Partners::class, 'adminLink'],
        'GET admin/partners/registry-img' => [Partners::class, 'adminRegistryImg'],
        'GET verify/me'              => [Verify::class, 'me'],
        'POST verify/resubmit'       => [Verify::class, 'resubmit'],
        'GET admin/verify'           => [Verify::class, 'adminList'],
        'GET admin/verify/pending-count' => [Verify::class, 'adminPendingCount'],
        'GET admin/verify/img'       => [Verify::class, 'adminImg'],
        'POST admin/verify/review'   => [Verify::class, 'adminReview'],
        'POST assistant/ask'         => [Assistant::class, 'ask'],
        'POST visits'           => [Rewards::class, 'visit'],
        'POST visits/confirm'   => [Rewards::class, 'confirmVisit'],
        'GET notifications'     => [Notifications::class, 'index'],
        'POST notifications/read' => [Notifications::class, 'markRead'],
        'GET admin/notifications' => [Notifications::class, 'adminList'],
        'POST admin/notifications' => [Notifications::class, 'adminSend'],
        'POST admin/notifications/delete' => [Notifications::class, 'adminDelete'],
        'GET admin/visits'      => [Admin::class, 'visits'],
        'GET merchant/menu'     => [Shop::class, 'menuList'],
        'POST merchant/menu/add' => [Shop::class, 'menuAdd'],
        'POST merchant/menu/delete' => [Shop::class, 'menuDelete'],
        'GET merchant/offers'   => [Shop::class, 'offersList'],
        'POST merchant/offers/add' => [Shop::class, 'offerAdd'],
        'POST merchant/offers/remove' => [Shop::class, 'offerRemove'],
        'GET place/extras'      => [Shop::class, 'placeExtras'],
        'GET merchant/stats'    => [Shop::class, 'stats'],
        'GET offer/img'         => [Shop::class, 'offerImg'],
        'GET menu/img'          => [Shop::class, 'menuImg'],
        'GET reviews'           => [Reviews::class, 'index'],
        'POST reviews'          => [Reviews::class, 'save'],
        'POST reviews/delete'   => [Reviews::class, 'remove'],
        'POST feedback'         => [Feedback::class, 'submit'],
        'POST coupons/request'  => [Rewards::class, 'requestCoupon'],
        'POST coupons/redeem'   => [Rewards::class, 'redeem'],
        'GET admin/setup-status'=> [Admin::class, 'setupStatus'],
        'POST admin/setup'      => [Admin::class, 'setup'],
        'GET admin/me'          => [Admin::class, 'me'],
        'GET admin/home'        => [Admin::class, 'home'],
        'GET admin/stats'       => [Admin::class, 'stats'],
        'POST admin/users/update' => [Admin::class, 'updateUser'],
        'POST admin/users/mail' => [Admin::class, 'mailUser'],
        'POST admin/places/delete' => [Admin::class, 'deletePlace'],
        'GET admin/staff'       => [Staff::class, 'list'],
        'POST admin/staff/create' => [Staff::class, 'create'],
        'POST admin/staff/appoint' => [Staff::class, 'appoint'],
        'POST admin/staff/perms' => [Staff::class, 'setPerms'],
        'POST admin/staff/remove' => [Staff::class, 'remove'],
        'POST admin/staff/status' => [Staff::class, 'status'],
        'GET admin/logs'        => [Logs::class, 'list'],
        'GET admin/logs/verify' => [Logs::class, 'verify'],
        'GET admin/users'       => [Admin::class, 'users'],
        'POST admin/users/status' => [Admin::class, 'setUserStatus'],
        'POST admin/users/delete' => [Admin::class, 'deleteUser'],
        'GET admin/explore'     => [Admin::class, 'exploreList'],
        'GET admin/usage'       => [Admin::class, 'usage'],
        'GET admin/feedback'    => [Feedback::class, 'adminList'],
        'POST admin/feedback/status' => [Feedback::class, 'setStatus'],
        'GET admin/uploads'     => [Admin::class, 'uploadsList'],
        'POST admin/uploads/approve' => [Admin::class, 'approveUpload'],
        'POST admin/uploads/delete'  => [Admin::class, 'deleteUpload'],
        'POST admin/explore'    => [Admin::class, 'saveExplore'],
        'POST admin/explore/delete' => [Admin::class, 'deleteExplore'],
        'POST admin/explore/update' => [Admin::class, 'updateExplore'],
        'GET admin/gallery'     => [Admin::class, 'galleryList'],
        'POST admin/gallery'    => [Admin::class, 'galleryAdd'],
        'POST admin/gallery/update' => [Admin::class, 'galleryUpdate'],
        'POST admin/gallery/delete' => [Admin::class, 'galleryDelete'],
        'POST share/claim'      => [Rewards::class, 'shareClaim'],
        'GET admin/share'       => [Admin::class, 'shareList'],
        'POST admin/share/settings' => [Admin::class, 'shareSettings'],
        'POST admin/share/regrant' => [Admin::class, 'shareRegrant'],
        'GET admin/places'      => [Admin::class, 'places'],
        'POST admin/places'     => [Admin::class, 'savePlace'],
    ];

    $key = $method . ' ' . $route;
    if (!isset($routes[$key])) { throw new ApiException('not_found', 'Not found', 404); }

    Auth::boot();
    if ($method !== 'GET') { Security::guardWrite(); }

    [$class, $fn] = $routes[$key];
    $class::$fn();
} catch (ApiException $e) {
    // أحداث أمنية: تتسجل في سجل الموقع (مع تجميع التكرار عشان الهجمات ما تغرقش السجل)
    $sec = ['csrf' => 'خرق حماية CSRF', 'origin' => 'طلب من مصدر غير مسموح', 'content_type' => 'طلب بصيغة غير مسموحة', 'rate_limited' => 'تجاوز حد الطلبات'];
    if (isset($sec[$e->errCode])) {
        Audit::event((static function () { try { return Auth::user(); } catch (\Throwable $x) { return null; } })(), 'security', $e->errCode === 'rate_limited' ? 'warning' : 'critical', $sec[$e->errCode] . ' على المسار: ' . ($route ?? ''), ['code' => $e->errCode], 300);
    } elseif ($e->errCode === 'unauthorized' && strncmp($route ?? '', 'admin/', 6) === 0 && ($route ?? '') !== 'admin/me') {
        Audit::event(null, 'access_denied', 'notice', 'محاولة وصول لمسار إداري بدون تسجيل دخول: ' . $route, [], 300);
    }
    Http::fail($e->errCode, $e->getMessage(), $e->status);
} catch (\Throwable $e) {
    error_log('[api] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Audit::event(null, 'error', 'critical', 'خطأ داخلي في السيرفر على المسار: ' . ($route ?? ''), ['type' => get_class($e)], 60);
    Http::fail('server', 'حصلت مشكلة في السيرفر. حاول تاني بعد شوية.', 500);
}
