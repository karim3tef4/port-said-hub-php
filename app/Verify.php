<?php
declare(strict_types=1);
namespace App;

/** توثيق الطلبة: صورة الكارنيه بتتبعت وقت التسجيل، والحساب شغّال عادي لحد ما الإدارة تراجع وتوافق. */
final class Verify
{
    /** حالة التوثيق بتاعتي (لو طالب). */
    public static function me(): void
    {
        $u = Auth::need();
        $v = Db::one('SELECT status, admin_note, created_at FROM id_verifications WHERE user_id = ?', [$u['id']]);
        Http::ok(['verification' => $v ? ['status' => $v['status'], 'admin_note' => $v['admin_note'], 'created_at' => $v['created_at']] : null]);
    }

    /** إعادة رفع صورة الكارنيه لو الطلب اتأجل. */
    public static function resubmit(): void
    {
        $u = Auth::need();
        RateLimiter::hit('verify-resubmit:' . $u['id'], 5, 3600);
        $v = Db::one('SELECT id, status FROM id_verifications WHERE user_id = ?', [$u['id']]);
        if (!$v) { throw new ApiException('not_found', 'مفيش طلب توثيق على حسابك.', 404); }
        if ($v['status'] === 'approved') { throw new ApiException('validation', 'حسابك متوثّق بالفعل.', 422); }
        $b = Http::body(3600000);
        if (!isset($b['id_card']) || trim((string)$b['id_card']) === '') { throw new ApiException('validation', 'ارفع صورة الكارنيه.', 422); }
        $jpg = ImageUtil::toJpegB64((string)$b['id_card'], 1400, 80);
        Db::run("UPDATE id_verifications SET img_b64 = ?, status = 'pending', admin_note = NULL, reviewed_by = NULL, reviewed_at = NULL WHERE id = ?", [$jpg, $v['id']]);
        Audit::event($u, 'verify', 'info', Audit::who($u) . ' أعاد رفع صورة الكارنيه للمراجعة');
        Http::ok(['sent' => true]);
    }

    /* ---------------- الأدمن ---------------- */

    public static function adminPendingCount(): void
    {
        Auth::needPerm('students.verify');
        Http::ok(['count' => (int)Db::one("SELECT COUNT(*) AS n FROM id_verifications WHERE status = 'pending'")['n']]);
    }

    public static function adminList(): void
    {
        Auth::needPerm('students.verify');
        $rows = Db::all(
            "SELECT v.uuid AS id, v.status, v.admin_note, v.created_at,
                    u.uuid AS user_id, u.name AS owner_name, u.email AS owner_email, u.phone,
                    i.name_ar AS institution
             FROM id_verifications v
             JOIN users u ON u.id = v.user_id
             LEFT JOIN institutions i ON i.id = u.institution_id
             ORDER BY (v.status = 'pending') DESC, v.id DESC LIMIT 200"
        );
        Http::ok(['items' => $rows]);
    }

    /** صورة الكارنيه — للأدمن صاحب صلاحية students.verify بس. */
    public static function adminImg(): void
    {
        Auth::needPerm('students.verify');
        $id = Validator::uuid($_GET['id'] ?? null);
        $row = Db::one('SELECT img_b64 FROM id_verifications WHERE uuid = ?', [$id]);
        $bin = $row ? base64_decode((string)$row['img_b64'], true) : false;
        if (!$row || $bin === false || $bin === '') { throw new ApiException('not_found', 'Not found', 404); }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string)strlen($bin));
        echo $bin;
        exit;
    }

    public static function adminReview(): void
    {
        $me = Auth::needPerm('students.verify');
        $b = Http::body();
        $id = Validator::uuid($b['id'] ?? null);
        $status = Validator::enum($b['status'] ?? null, ['approved', 'rejected'], 'الحالة');
        $note = (isset($b['note']) && trim((string)$b['note']) !== '') ? Validator::str($b['note'], 2, 500, 'الملاحظة') : null;
        if ($status === 'rejected' && $note === null) { throw new ApiException('validation', 'اكتب سبب الرفض.', 422); }
        $v = Db::one('SELECT id, user_id FROM id_verifications WHERE uuid = ?', [$id]);
        if (!$v) { throw new ApiException('not_found', 'الطلب مش موجود.', 404); }
        $owner = Db::one('SELECT name, email FROM users WHERE id = ?', [$v['user_id']]);
        Db::run('UPDATE id_verifications SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $note, $me['id'], $v['id']]);
        Audit::event($me, 'verify', $status === 'approved' ? 'notice' : 'warning', Audit::who($me) . ($status === 'approved' ? ' وثّق كارنيه ' : ' رفض كارنيه ') . Audit::who(['id' => $v['user_id'], 'name' => $owner['name'] ?? '', 'role' => 'user']) . ($note ? ' — ' . $note : ''));
        if ($status === 'approved') {
            Db::run('UPDATE users SET verified_at = UTC_TIMESTAMP() WHERE id = ? AND verified_at IS NULL', [$v['user_id']]);
            Notifications::sendTo($v['user_id'], 'تم التحقق منك ✅', 'مبروك، تم التحقق من كارنيهك والتأكد من إنك طالب. خصومات الطلبة الخاصة بقت متاحة لحسابك دلوقتي، ومرحبًا بيك!');
        }
        if ($owner && $owner['email']) {
            $msg = $status === 'approved'
                ? 'تم التحقق من كارنيهك والموافقة عليه. خصومات الطلبة بقت متاحة لحسابك.'
                : 'للأسف صورة الكارنيه اللي بعتها محتاجة مراجعة تانية.' . ($note ? "\nالسبب: " . $note : '') . "\nتقدر ترفع صورة تانية من حسابك.";
            Mailer::notice((string)$owner['email'], (string)$owner['name'], $status === 'approved' ? 'تم توثيق حسابك' : 'بخصوص توثيق الكارنيه', $msg);
        }
        Http::ok(['verified' => $status === 'approved']);
    }
}
