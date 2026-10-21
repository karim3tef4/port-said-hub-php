<?php
declare(strict_types=1);
namespace App;

/** كل مدخلات المستخدم تمر من هنا (قوائم سماح + حدود أطوال). */
final class Validator
{
    private static function bad(string $msg): ApiException { return new ApiException('validation', $msg, 422); }

    public static function str($v, int $min, int $max, string $label = 'الحقل'): string
    {
        if (!is_string($v)) { throw self::bad("$label مطلوب."); }
        $v = trim((string)preg_replace('/\s+/u', ' ', $v));
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) { throw self::bad("$label لازم يكون من $min إلى $max حرف."); }
        if (preg_match('/[\x00-\x1F\x7F]/u', $v)) { throw self::bad("$label فيه رموز غير مسموحة."); }
        return $v;
    }

    /** نص حر طويل بيحافظ على الأسطر الجديدة (للأوصاف والمقالات)، من غير رموز تحكم. */
    public static function text($v, int $min, int $max, string $label = 'الحقل'): string
    {
        if (!is_string($v)) { throw self::bad("$label مطلوب."); }
        $v = trim(str_replace("\r", '', $v));
        $v = (string)preg_replace("/\n{3,}/", "\n\n", $v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) { throw self::bad("$label لازم يكون من $min إلى $max حرف."); }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $v)) { throw self::bad("$label فيه رموز غير مسموحة."); }
        return $v;
    }

    public static function name($v): string
    {
        $v = self::str($v, 2, 100, 'الاسم');
        if (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'-]*$/u', $v)) { throw self::bad('الاسم فيه رموز غير مسموحة.'); }
        return $v;
    }

    public static function email($v): string
    {
        $v = strtolower(self::str($v, 5, 190, 'الإيميل'));
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) { throw self::bad('اكتب إيميل صحيح.'); }
        return $v;
    }

    /** رقم موبايل مصري: 11 رقم بالضبط ويبدأ بـ 01 (يقبل الأرقام العربية ويحوّلها). */
    public static function phone($v): string
    {
        if (!is_string($v) || trim($v) === '') { throw self::bad('اكتب رقم الهاتف.'); }
        $v = strtr(trim($v), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (preg_match('/\D/', $v)) { throw self::bad('رقم الهاتف لازم يكون أرقام بس.'); }
        if (strlen($v) < 11) { throw self::bad('رقم الهاتف ناقص: لازم 11 رقم.'); }
        if (!preg_match('/^01[0-9]{9}$/', $v)) { throw self::bad('رقم الهاتف لازم يكون 11 رقم ويبدأ بـ 01.'); }
        return $v;
    }

    public static function password($v, string $email = '', string $name = ''): string
    {
        if (!is_string($v) || strlen($v) < 8 || strlen($v) > 128) { throw self::bad('كلمة المرور لازم تكون من 8 إلى 128 حرف.'); }
        $low = strtolower($v);
        $weak = ['12345678', '123456789', '1234567890', 'password', 'password1', 'qwerty123', 'iloveyou', '11111111', 'abcd1234', 'admin123'];
        if (in_array($low, $weak, true) || $low === $email || $low === strtolower($name)) { throw self::bad('كلمة المرور ضعيفة، اختار حاجة أصعب.'); }
        return $v;
    }

    public static function enum($v, array $allowed, string $label = 'القيمة'): string
    {
        if (!is_string($v) || !in_array($v, $allowed, true)) { throw self::bad("$label غير صحيح."); }
        return $v;
    }

    public static function uuid($v): string
    {
        if (!is_string($v) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v)) { throw self::bad('المعرّف غير صحيح.'); }
        return $v;
    }

    public static function float($v, float $min, float $max, bool $required = false, string $label = 'القيمة'): ?float
    {
        if ($v === null || $v === '') {
            if ($required) { throw self::bad("$label مطلوب."); }
            return null;
        }
        if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) { throw self::bad("$label غير صحيح."); }
        $f = (float)$v;
        if ($f < $min || $f > $max) { throw self::bad("$label خارج النطاق."); }
        return $f;
    }

    public static function int($v, int $min, int $max, string $label = 'القيمة'): int
    {
        if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d+$/', $v))) { throw self::bad("$label غير صحيح."); }
        $i = (int)$v;
        if ($i < $min || $i > $max) { throw self::bad("$label خارج النطاق."); }
        return $i;
    }
}
