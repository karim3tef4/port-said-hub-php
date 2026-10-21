<?php
declare(strict_types=1);
namespace App;

final class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all('SELECT k, v FROM settings') as $r) { self::$cache[$r['k']] = $r['v']; }
        }
        return self::$cache[$key] ?? $default;
    }
}
