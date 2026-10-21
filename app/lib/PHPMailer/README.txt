ضع هنا ملفات مكتبة PHPMailer الرسمية (بدون Composer) داخل المجلد src/ :
  lib/PHPMailer/src/PHPMailer.php
  lib/PHPMailer/src/SMTP.php
  lib/PHPMailer/src/Exception.php
حمّلها من https://github.com/PHPMailer/PHPMailer (Releases) وانسخ هذه الملفات الثلاثة فقط من مجلد src.
بعدها فعّل قسم 'mail' في app/config.php.
