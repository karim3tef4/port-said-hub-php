<?php
declare(strict_types=1);
namespace App;

/** كتالوج الصلاحيات التفصيلية للأدمن. المالك (owner) يملكها كلها دائمًا ولا تُخزَّن له. */
final class Perms
{
    public const GROUPS = [
        'users'   => 'الحسابات',
        'places'  => 'الأماكن والمطاعم',
        'partners' => 'التجار والشركاء',
        'content' => 'المحتوى',
        'ops'     => 'التشغيل',
    ];

    /** code => [الاسم العربي، المجموعة، وصف قصير] */
    public const ALL = [
        'users.view'     => ['مشاهدة الحسابات',        'users',   'قائمة المسجلين وبياناتهم'],
        'users.edit'     => ['تعديل الحسابات',         'users',   'تعديل الاسم والهاتف والبريد'],
        'users.suspend'  => ['تعطيل / تفعيل الحسابات', 'users',   'مع كتابة سبب التعطيل'],
        'users.delete'   => ['حذف الحسابات',           'users',   'حذف نهائي لبيانات المستخدم'],
        'users.mail'     => ['مراسلة المستخدمين',      'users',   'إرسال بريد إلكتروني للمستخدمين'],
        'students.verify' => ['توثيق الطلبة',          'users',   'مراجعة صور كارنيهات الطلبة والموافقة عليها'],
        'places.view'    => ['مشاهدة الأماكن',          'places',  'قائمة الأماكن والمطاعم'],
        'places.add'     => ['إضافة مطاعم وأماكن',     'places',  'إضافة مكان جديد'],
        'places.edit'    => ['تعديل الأماكن',           'places',  'تعديل بيانات وإعدادات المكان'],
        'places.remove'  => ['إزالة مطاعم وأماكن',     'places',  'إزالة المكان من الموقع'],
        'partners.manage' => ['طلبات التجار',          'partners', 'مراجعة طلبات "سجل متجرك" وربطها بالأماكن'],
        'explore.manage' => ['إدارة استكشف بورسعيد',   'content', 'إضافة وحذف صور وفيديوهات'],
        'uploads.review' => ['مراجعة لقطات الزوار',    'content', 'قبول أو حذف صور الزوار'],
        'feedback.manage' => ['الشكاوى والاقتراحات',    'content', 'قراءة الشكاوى والاقتراحات وتغيير حالتها'],
        'notifications.send' => ['إرسال الإشعارات',     'content', 'كتابة إشعارات بتوصل لكل الحسابات وحذفها'],
        'points.adjust'  => ['تعديل نقاط الحسابات',    'ops',     'إنقاص نقاط حساب أو تصفيرها'],
        'share.manage'   => ['إدارة شير واكسب',        'ops',     'تغيير نقاط الشير وإعادة تنزيلها للي شيروا'],
        'stats.view'     => ['مشاهدة الإحصائيات',      'ops',     'الإحصائيات وأكثر الأقسام دخولًا'],
        'coupons.redeem' => ['تسوية الكوبونات',        'ops',     'تسوية كوبونات الخصم (QR)'],
    ];

    /** صلاحية تستلزم صلاحية أخرى لتكون مفيدة. */
    private const IMPLIES = [
        'users.edit' => 'users.view', 'users.suspend' => 'users.view', 'users.delete' => 'users.view', 'users.mail' => 'users.view',
        'places.add' => 'places.view', 'places.edit' => 'places.view', 'places.remove' => 'places.view',
    ];

    public static function codes(): array { return array_keys(self::ALL); }

    public static function catalog(): array
    {
        $o = [];
        foreach (self::ALL as $code => $v) { $o[] = ['code' => $code, 'label' => $v[0], 'group' => $v[1], 'group_label' => self::GROUPS[$v[1]], 'hint' => $v[2]]; }
        return $o;
    }

    /** يقبل مصفوفة من الأكواد من العميل: يشيل المجهول والمكرر ويضيف الصلاحيات المطلوبة ضمنيًا. */
    public static function normalize($in): array
    {
        if (!is_array($in)) { return []; }
        $out = [];
        foreach ($in as $p) {
            if (is_string($p) && isset(self::ALL[$p])) {
                $out[$p] = true;
                if (isset(self::IMPLIES[$p])) { $out[self::IMPLIES[$p]] = true; }
            }
        }
        $r = array_keys($out);
        sort($r);
        return $r;
    }

    public static function label(string $code): string { return self::ALL[$code][0] ?? $code; }
}
