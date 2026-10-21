<?php
// إعدادات الموقع (اسم الملف config.local.php عمدًا: عشان ما يتعارض مع كلاس Config.php على ويندوز/FTP). ما ترفعش الملف ده على GitHub.
// املأ بس بيانات القاعدة (host / name / user / pass) من لوحة InfinityFree > MySQL Databases.
return [
    'db' => [
        'host' => 'sql301.infinityfree.com',      // <-- غيّره (MySQL Hostname)
        'port' => 3306,
        'name' => 'if0_43176881_pshub',     // <-- غيّره (MySQL DB Name)
        'user' => 'if0_43176881',                 // <-- غيّره (MySQL User Name)
        'pass' => '3z9zKMVQt090',                    // <-- غيّره (كلمة مرور القاعدة / vPanel password)
    ],
    'app' => [
        'secret'           => '891c16cbd0d96698d73d09127c64c8e6a89c4683479446f4be4d54477e40467f',
        'setup_token'      => '',                 // الأدمن اتجهّز بـ 04_admin_account.sql فمش محتاجينه
        'allowed_hosts'    => [],                 // الدومين الحالي مسموح تلقائيًا
        'trust_proxy'      => false,
        'session_idle'     => 28800,
        'session_absolute' => 604800,
        'base_url'         => '',                 // مثال: https://yourdomain.com (لروابط البريد)
    ],
    // البريد عبر PHPMailer (انظر app/lib/PHPMailer/README.txt). فعّله بعد ملء البيانات.
    'mail' => [
        'enabled'   => true,
        'host'      => 'smtp.gmail.com',
        'port'      => 587,
        'secure'    => 'tls',
        'user'      => 'portsaidhub@gmail.com',
        'pass'      => 'Kreem8187#@',   // لو الإرسال فشل: جيميل بيطلب "كلمة مرور تطبيقات" (App Password) بدل كلمة مرور الحساب
        'from'      => 'portsaidhub@gmail.com',
        'from_name' => 'Port Said Hub',
        'timeout'   => 8,
    ],
    // مساعد بورسعيد (شات بوت بالذكاء الاصطناعي). هات مفتاح API من https://console.anthropic.com
    // لو سبته فاضي، الشات بوت هيشتغل بالأسئلة الجاهزة بس من غير ذكاء اصطناعي (مفيش أي كسر في الموقع).
    'ai' => [
        'api_key' => '',                 // CHANGE_ME: مفتاح Anthropic API (sk-ant-...)
        'model'   => 'claude-sonnet-4-5',
    ],
];
