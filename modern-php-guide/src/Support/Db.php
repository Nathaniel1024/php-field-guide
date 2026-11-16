<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Db
{
    public static function connect(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $pdo = new PDO('sqlite:' . dirname(__DIR__, 2) . '/database.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }
}
