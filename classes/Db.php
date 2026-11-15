<?php
declare(strict_types=1);

namespace RaPostcode;

use mysqli;

/** Thin connection helper. Throws mysqli_sql_exception on any error (strict mode). */
final class Db
{
    public static function connect(array $cfg): mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name']);
        $db->set_charset('utf8mb4');
        return $db;
    }
}
