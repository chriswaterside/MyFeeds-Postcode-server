<?php
declare(strict_types=1);

namespace RaPostcode;

use mysqli;

/** Table definitions. The importer builds a fresh table each release and swaps it in. */
final class Schema
{
    /** SQL to create a postcode table with the given name. */
    public static function createPostcodes(string $table): string
    {
        return "CREATE TABLE `$table` (
            `postcode_key` varchar(7) NOT NULL COMMENT 'upper case, no spaces; used for lookups',
            `postcode` varchar(8) NOT NULL COMMENT 'as supplied by OS',
            `quality` tinyint unsigned NOT NULL COMMENT 'OS positional quality indicator',
            `easting` mediumint NOT NULL,
            `northing` mediumint NOT NULL,
            PRIMARY KEY (`postcode_key`),
            KEY `en` (`easting`, `northing`)
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin";
    }

    public static function ensureMeta(mysqli $db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS `import_meta` (
            `k` varchar(32) NOT NULL,
            `v` varchar(255) NOT NULL,
            PRIMARY KEY (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii");
    }

    public static function tableExists(mysqli $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $stmt->bind_result($n);
        $stmt->fetch();
        $stmt->close();
        return $n > 0;
    }

    public static function getMeta(mysqli $db, string $key): ?string
    {
        $stmt = $db->prepare('SELECT v FROM import_meta WHERE k = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->bind_result($v);
        $found = $stmt->fetch();
        $stmt->close();
        return $found ? $v : null;
    }

    public static function setMeta(mysqli $db, string $key, string $value): void
    {
        $stmt = $db->prepare('INSERT INTO import_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}
