<?php
declare(strict_types=1);
namespace App;

final class Config
{
    /** @return mixed */
    public static function get(string $path, $default = null)
    {
        $v = $GLOBALS['cfg'] ?? [];
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
            $v = $v[$k];
        }
        return $v;
    }
}
