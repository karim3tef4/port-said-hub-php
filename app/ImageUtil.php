<?php
declare(strict_types=1);
namespace App;

/** تحويل صورة base64 مرفوعة من المستخدم لصورة JPEG آمنة (بيشيل أي بيانات مخفية وGPS) وبيتأكد إنها صورة حقيقية. */
final class ImageUtil
{
    /**
     * @param string $b64Raw الصورة Base64 كما وصلت من العميل.
     * @param int $maxDim أقصى بعد (عرض أو ارتفاع) للصورة الناتجة.
     * @param int $quality جودة JPEG من 0 إلى 100.
     * @return string الصورة بعد إعادة الترميز، Base64.
     */
    public static function toJpegB64(string $b64Raw, int $maxDim = 1600, int $quality = 82): string
    {
        $raw = base64_decode($b64Raw, true);
        if ($raw === false || strlen($raw) < 1000 || strlen($raw) > 6000000) {
            throw new ApiException('validation', 'الصورة مش مقبولة (حجمها أو صيغتها).', 422);
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            throw new ApiException('unsupported', 'رفع الصور مش متاح على السيرفر ده حاليًا.', 501);
        }
        $info = @getimagesizefromstring($raw);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new ApiException('validation', 'اختار صورة JPG أو PNG أو WebP.', 422);
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w < 150 || $h < 150 || $w > 8000 || $h > 8000 || $w * $h > 40000000) {
            throw new ApiException('validation', 'مقاس الصورة مش مناسب.', 422);
        }
        $src = @imagecreatefromstring($raw);
        if (!$src) { throw new ApiException('validation', 'مقدرتش أقرا الصورة دي. جرّب صورة تانية.', 422); }
        $k = min(1.0, $maxDim / max($w, $h));
        $W = max(1, (int)round($w * $k));
        $H = max(1, (int)round($h * $k));
        $dst = imagecreatetruecolor($W, $H);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $W, $H, $w, $h);
        ob_start();
        imagejpeg($dst, null, $quality);
        $jpg = (string)ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        if ($jpg === '') { throw new ApiException('server', 'حصلت مشكلة في معالجة الصورة.', 500); }
        return base64_encode($jpg);
    }
}
