<?php
// انسخ الملف باسم config.local.php (مش config.php) واملأ القيم. لا ترفع config.local.php على GitHub أبدًا.
return [
    'db' => [
        'host' => 'sql301.infinityfree.com', // من لوحة التحكم > MySQL Databases
        'port' => 3306,
        'name' => 'if0_43176881_pshub',
        'user' => 'if0_43176881',
        'pass' => '3z9zKMVQt090',
    ],
    'app' => [
        // مفتاح عشوائي طويل (64 حرف hex على الأقل) لتوقيع أكواد الـ QR. غيّره ولا تشاركه.
        'secret'         => 'CHANGE_ME_64_HEX_CHARS_OR_MORE',
        // كود لمرة واحدة لتفعيل أول أدمن من صفحة /admin/ (32 حرف على الأقل). لو فاضي الإعداد معطّل.
        'setup_token'    => '',
        'allowed_hosts'  => ['yourdomain.com', 'www.yourdomain.com'], // الدومينات المسموح لها بإرسال طلبات
        'trust_proxy'    => false,   // فعّلها فقط لو خلف Cloudflare/بروكسي موثوق
        'session_idle'   => 28800,   // 8 ساعات خمول
        'session_absolute' => 604800,// 7 أيام كحد أقصى
        'base_url'       => '',      // مثال: https://yourdomain.com (يُستخدم في روابط البريد). لو فاضي يُستنتج من الدومين
    ],
    // البريد (PHPMailer عبر SMTP). ضع ملفات المكتبة في app/lib/PHPMailer/src/ (انظر README.txt هناك).
    'mail' => [
        'enabled'   => false,
        'host'      => 'smtp.gmail.com',
        'port'      => 587,
        'secure'    => 'tls',          // tls أو ssl أو none
        'user'      => 'you@gmail.com',
        'pass'      => 'APP_PASSWORD',  // كلمة مرور تطبيقات (App Password) وليست كلمة مرور الحساب
        'from'      => 'you@gmail.com',
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
