<?php
declare(strict_types=1);

namespace RaPostcode;

use mysqli;
use RuntimeException;
use ZipArchive;

/**
 * Loads a Code-Point Open zip into a staging table, checks it, then swaps it in atomically.
 * The live table is never half-updated and postcodes that OS has dropped disappear with the swap.
 */
final class Importer
{
    private const BATCH = 1000;
    private const STAGING = 'postcodes_new';
    private const OLD = 'postcodes_old';

    public function __construct(private mysqli $db, private Logger $log, private array $config)
    {
    }

    /** @return array{rows:int,skipped:int,files:int} */
    public function importZip(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open zip $zipPath");
        }
        $csvs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#(^|/)Data/CSV/[^/]+\.csv$#i', $name)) {
                $csvs[] = $name;
            }
        }
        sort($csvs);
        if (!$csvs) {
            throw new RuntimeException('Zip contains no Data/CSV/*.csv files - is this a Code-Point Open CSV download?');
        }
        $this->log->info('Found ' . count($csvs) . ' CSV files in zip');

        $this->db->query('DROP TABLE IF EXISTS `' . self::STAGING . '`');
        $this->db->query(Schema::createPostcodes(self::STAGING));

        $total = 0;
        $skipped = 0;
        $fileCount = count($csvs);
        foreach ($csvs as $i => $name) {
            $stream = $zip->getStream($name);
            if (!$stream) {
                throw new RuntimeException("Cannot read $name from zip");
            }
            [$n, $s, $samples] = $this->loadCsv($stream);
            fclose($stream);
            $total += $n;
            $skipped += $s;
            $this->log->info(sprintf('[%d/%d] %s: loaded %d, skipped %d (running total %d)', $i + 1, $fileCount, basename($name), $n, $s, $total));
            foreach ($samples as $sample) {
                $this->log->info("    skipped: $sample");
            }
        }
        $zip->close();

        $this->swapIn($total);
        return ['rows' => $total, 'skipped' => $skipped, 'files' => count($csvs)];
    }

    /** @param resource $stream @return array{0:int,1:int,2:string[]} [rows loaded, rows skipped, sample skipped rows] */
    private function loadCsv($stream): array
    {
        $rows = [];
        $loaded = 0;
        $skipped = 0;
        $samples = [];
        $this->db->begin_transaction();
        $batches = 0;
        while (($f = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            // Code-Point Open columns: PC, PQ, EA, NO, ... only the first four are used
            if (count($f) < 4 || !self::validRow($f)) {
                // header rows, blank lines, quality-90 rows with no coordinates, etc.
                if (!(count($f) === 1 && trim((string) $f[0]) === '')) {
                    $skipped++;
                    if (!empty($this->config['show_skipped']) && count($samples) < 5) {
                        $samples[] = substr(implode(',', $f), 0, 120);
                    }
                }
                continue;
            }
            $rows[] = $f;
            if (count($rows) === self::BATCH) {
                $this->insertBatch($rows);
                $loaded += count($rows);
                $rows = [];
                if (++$batches % 20 === 0) {
                    $this->db->commit();
                    $this->db->begin_transaction();
                }
            }
        }
        if ($rows) {
            $this->insertBatch($rows);
            $loaded += count($rows);
        }
        $this->db->commit();
        return [$loaded, $skipped, $samples];
    }

    private static function validRow(array $f): bool
    {
        $key = strtoupper(str_replace(' ', '', $f[0]));
        return preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/', $key) === 1
            && ctype_digit($f[1]) && ctype_digit($f[2]) && ctype_digit($f[3])
            && (int) $f[2] > 0 && (int) $f[3] > 0   // quality 90 = no coordinates (0,0)
            && (int) $f[1] <= 255 && (int) $f[2] <= 700000 && (int) $f[3] <= 1300000;
    }

    private function insertBatch(array $rows): void
    {
        static $cache = [];
        $n = count($rows);
        if (!isset($cache[$n])) {
            $sql = 'INSERT INTO `' . self::STAGING . '` (postcode_key, postcode, quality, easting, northing) VALUES '
                . implode(',', array_fill(0, $n, '(?,?,?,?,?)'))
                . ' ON DUPLICATE KEY UPDATE postcode=VALUES(postcode), quality=VALUES(quality), easting=VALUES(easting), northing=VALUES(northing)';
            $cache[$n] = $this->db->prepare($sql);
        }
        $params = [];
        foreach ($rows as $f) {
            $pc = strtoupper(trim($f[0]));
            array_push($params, str_replace(' ', '', $pc), $pc, (int) $f[1], (int) $f[2], (int) $f[3]);
        }
        $cache[$n]->bind_param(str_repeat('ssiii', $n), ...$params);
        $cache[$n]->execute();
    }

    private function swapIn(int $loadedRows): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) FROM `' . self::STAGING . '`')->fetch_row()[0];
        $this->log->info("Staging table holds $count postcodes");

        $minAbs = (int) $this->config['min_rows_absolute'];
        if ($count < $minAbs) {
            throw new RuntimeException("Only $count postcodes loaded (minimum $minAbs): refusing to replace the live table");
        }
        $liveExists = Schema::tableExists($this->db, 'postcodes');
        if ($liveExists) {
            $live = (int) $this->db->query('SELECT COUNT(*) FROM postcodes')->fetch_row()[0];
            $floor = (int) floor($live * (float) $this->config['min_rows_ratio']);
            if ($count < $floor) {
                throw new RuntimeException("New data has $count postcodes, live has $live (floor $floor): refusing to replace the live table");
            }
            $this->db->query('DROP TABLE IF EXISTS `' . self::OLD . '`');
            $this->db->query('RENAME TABLE postcodes TO `' . self::OLD . '`, `' . self::STAGING . '` TO postcodes');
            $this->db->query('DROP TABLE `' . self::OLD . '`');
        } else {
            $this->db->query('RENAME TABLE `' . self::STAGING . '` TO postcodes');
        }
        $this->log->info('New postcode table is live');
    }
}
