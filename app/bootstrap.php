<?php
declare(strict_types=1);
if (PHP_VERSION_ID < 80000) { http_response_code(500); exit('PHP 8.0+ required'); }
define('APP_ROOT', __DIR__);
$cfgFile = APP_ROOT . '/config.local.php';
if (!is_file($cfgFile)) { $cfgFile = APP_ROOT . '/config.php'; }   // توافق مع النسخ القديمة
if (!is_file($cfgFile)) {
    http_response_code(500); header('Content-Type: application/json; charset=utf-8');
    exit('{"ok":false,"error":{"code":"config","message":"Server not configured"}}');
}
$GLOBALS['cfg'] = require $cfgFile;
if (!is_array($GLOBALS['cfg'])) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); exit('{"ok":false,"error":{"code":"config","message":"Server not configured"}}'); }
spl_autoload_register(static function (string $c): void {
    if (strncmp($c, 'App\\', 4) === 0) {
        $f = APP_ROOT . '/' . str_replace('\\', '/', substr($c, 4)) . '.php';
        if (is_file($f)) { require $f; }
    }
});
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
error_reporting(E_ALL);
