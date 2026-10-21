<?php
// فحص سريع للتشخيص: افتح /api/health.php — لا يعرض أي أسرار. احذف الملف بعد ما كل حاجة تشتغل.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$r = ['php' => PHP_VERSION, 'php_ok' => PHP_VERSION_ID >= 80000];
$app = dirname(__DIR__) . '/app';
$cfgFile = is_file($app . '/config.local.php') ? $app . '/config.local.php' : $app . '/config.php';
$r['config_name'] = basename($cfgFile);
$cc = is_file($app . '/Config.php') ? (string)file_get_contents($app . '/Config.php') : '';
$r['config_class_ok'] = strpos($cc, 'final class Config') !== false;   // false = ملف Config.php اتبدّل بملف الإعدادات (سبب شائع لخطأ 500)
$r['config_file'] = is_file($cfgFile);
$cfg = null;
if ($r['config_file']) {
    try { $cfg = require $cfgFile; $r['config_valid'] = is_array($cfg); }
    catch (Throwable $e) { $r['config_valid'] = false; }
}
$r['config_has_placeholders'] = false;
if (is_array($cfg)) {
    $d = $cfg['db'] ?? [];
    $r['config_has_placeholders'] = (strpos((string)($d['host'] ?? ''), 'XXX') !== false || strpos((string)($d['name'] ?? ''), 'XXXX') !== false || ($d['pass'] ?? '') === 'CHANGE_ME' || ($d['pass'] ?? '') === '');
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], (int)($d['port'] ?? 3306), $d['name']), $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $r['db_connect'] = true;
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $r['users_columns_missing'] = array_values(array_diff(['phone','email_verified_at','avatar','disabled_reason','disabled_at','disabled_by'], $cols));
        $need = ['roles', 'users', 'user_types', 'governorates', 'institutions', 'rate_limits', 'audit_logs', 'settings', 'places', 'activity_log', 'admin_permissions', 'email_tokens'];
        $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $r['tables_missing'] = array_values(array_diff($need, $have));
        $ud2 = dirname(__DIR__) . '/assets/explore/users';
        $r['uploads_in_db'] = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'explore_uploads' AND COLUMN_NAME = 'img_b64'")->fetchColumn();   // 1 = تخزين الصور في القاعدة شغال
        $r['uploads_on_disk'] = is_dir($ud2) ? count(glob($ud2 . '/u_*.jpg') ?: []) : -1;
        if (in_array('explore_uploads', $have, true)) {
            $miss = 0;
            foreach ($pdo->query('SELECT file_name FROM explore_uploads')->fetchAll(PDO::FETCH_COLUMN) as $fn) { if (!is_file($ud2 . '/' . $fn)) { $miss++; } }
            $r['uploads_missing_files'] = $miss;   // > 0 = صفوف في القاعدة ملفها مش موجود على السيرفر
        }
        if (!$r['tables_missing']) {
            $r['roles'] = (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
            $r['institutions'] = (int)$pdo->query('SELECT COUNT(*) FROM institutions')->fetchColumn();
            $r['admin_ready'] = (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code IN ('owner','admin') AND u.password_hash <> '!' AND u.deleted_at IS NULL")->fetchColumn() > 0;
        }
    } catch (Throwable $e) {
        $r['db_connect'] = false;
        $r['db_error_code'] = $e instanceof PDOException ? (string)$e->getCode() : get_class($e);   // 1045 = بيانات دخول غلط، 1049 = اسم القاعدة غلط، 2002 = الـhost غلط
    }
}
$sp = session_save_path();
$r['session_path_writable'] = $sp === '' ? true : is_writable($sp);
$r['argon2id'] = defined('PASSWORD_ARGON2ID');
$r['https'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$r['pdo_mysql'] = extension_loaded('pdo_mysql');
$r['gd_images'] = function_exists('imagecreatefromstring') && function_exists('imagejpeg');   // لازم لرفع صور الزوار
$ud = dirname(__DIR__) . '/assets/explore/users';
$r['uploads_dir_writable'] = is_dir($ud) ? is_writable($ud) : is_writable(dirname($ud));
$lg = $app . '/storage/logs/php-error.log';
if (is_file($lg)) { $r['last_errors'] = array_map(static fn($l) => mb_substr($l, 0, 300), array_slice(file($lg, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -3)); }
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
