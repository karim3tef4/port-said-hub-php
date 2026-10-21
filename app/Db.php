<?php
declare(strict_types=1);
namespace App;

use PDO;

/** طبقة قاعدة البيانات: PDO + Prepared Statements حقيقية فقط (بدون محاكاة). */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $d = Config::get('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], (int)($d['port'] ?? 3306), $d['name']);
            self::$pdo = new PDO($dsn, $d['user'], $d['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', "
                    . "sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ]);
        }
        return self::$pdo;
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $p = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    public static function run(string $sql, array $p = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $p = []): int
    {
        self::run($sql, $p);
        return (int)self::pdo()->lastInsertId();
    }

    /** @return mixed */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
}
