<?php
declare(strict_types=1);

// Integration tests. Needs a MySQL/MariaDB database you can write to (NOT your live one).
//   1. Create a scratch database whose name contains "test" (e.g. postcodes_test) and give the
//      importer user ALL-but-grant rights on it (SELECT, INSERT, UPDATE, CREATE, DROP, ALTER).
//   2. Copy config.sample.php to test-config.php and point both db_* settings at that database.
//   3. php tests/run_tests.php --config=/full/path/to/test-config.php
//      (or set the RA_POSTCODE_CONFIG environment variable instead of --config)
// Command line only. The tests DROP and recreate the postcodes table, so the script refuses to run
// against any database whose name does not contain "test".

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run the tests from the command line, not through a web server.\n");
}

require __DIR__ . '/../bootstrap.php';

foreach ($argv as $arg) {
    if (strncmp($arg, '--config=', 9) === 0) {
        putenv('RA_POSTCODE_CONFIG=' . substr($arg, 9));
    }
}

use RaPostcode\Db;
use RaPostcode\Gridsquares;
use RaPostcode\Importer;
use RaPostcode\Logger;
use RaPostcode\PostcodeRepository;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok || $detail === '' ? '' : " ($detail)") . "\n";
}

try {
    $config = ra_load_config();
} catch (Throwable $e) {
    exit($e->getMessage() . "\nPass --config=/path/to/test-config.php\n");
}
foreach (['db_read', 'db_admin'] as $key) {
    if (stripos($config[$key]['name'], 'test') === false) {
        exit("Refusing to run: {$key} database '{$config[$key]['name']}' does not contain \"test\".\n"
            . "The tests drop and recreate the postcodes table. Use a scratch database.\n");
    }
}
$config['min_rows_absolute'] = 0;
$tmp = sys_get_temp_dir() . '/ra-pc-test-' . getmypid();
mkdir($tmp);
$log = new Logger($tmp . '/logs', 1, false);

// --- pure functions -------------------------------------------------------
echo "Grid squares\n";
check('Ben Nevis area is NN', Gridsquares::getSquare(216600, 771200)[0] === 'NN');
check('10 figure gridref', Gridsquares::getSquare(216600, 771200)[1] === 'NN1660071200');
check('London is TQ', Gridsquares::getSquare(530000, 180000)[0] === 'TQ');
check('zero coordinates give no square', Gridsquares::getSquare(0, 0) === ['', '']);
check('outside table gives no square', Gridsquares::getSquare(690000, 1290000) === ['', '']);

echo "Postcode validation\n";
foreach (['AB113DF', 'M11AE', 'SW1A1AA', 'EC1A1BB', 'W1A0AX', 'DE221JT'] as $pc) {
    check("valid $pc", PostcodeRepository::isValidPostcode($pc));
}
foreach (['', 'HELLO', '1AB2CD', 'AB113DF" OR 1=1', 'AB11 3DF', "A1 1AA'; DROP"] as $pc) {
    check("invalid '" . $pc . "'", !PostcodeRepository::isValidPostcode($pc));
}
check('normalise', PostcodeRepository::normalise(' ab11 3df ') === 'AB113DF');

// --- build a synthetic Code-Point Open style zip -----------------------------
// real files have no header row; the column header file lives in Doc/. We add a stray header and bad rows to prove they are skipped.
function makeZip(string $path, array $files): void
{
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $z->addFromString($name, $content);
    }
    $z->close();
}
mt_srand(42);
$ab = '';
$n = 0;
for ($i = 0; $i < 2500; $i++) {   // spread across a 5km x 5km patch near Aberdeen
    $e = 390000 + mt_rand(0, 5000);
    $no = 800000 + mt_rand(0, 5000);
    $pc = 'AB1' . mt_rand(0, 9) . mt_rand(0, 9) . chr(65 + $i % 26) . chr(65 + intdiv($i, 26) % 26);
    $ab .= "\"$pc\",10,$e,$no,\"S92000003\",\"\",\"\"\n";
    $n++;
}
$ab .= "\"PC\",\"PQ\",\"EA\",\"NO\"\n";                      // stray header
$ab .= "\"AB99 9ZZ\",90,0,0,\"S92000003\",\"\",\"\"\n";      // quality 90: no coordinates
$ab .= "\"NOTAPOSTCODE\",10,390000,800000\n";                 // junk
$ab .= "\n";                                                  // blank line
$ab .= "\"AB10 1AA\",10,394251,806376,\"S92000003\"\n";       // has a space; should be keyed AB101AA
$n++;
$ab .= "\"AB10 1AA\",10,394251,806376\n";                     // duplicate key: must not fail
$ld = "\"LD1 5DG\",10,328000,258000,\"W92000004\"\n\"ZE1 0AA\",10,444000,1141000,\"S92000003\"\n";
makeZip("$tmp/a.zip", ['Doc/licence.txt' => 'x', 'Doc/Code-Point_Open_Column_Headers.csv' => "PC,PQ,EA,NO\n", 'Data/CSV/ab.csv' => $ab, 'Data/CSV/ld.csv' => $ld]);

echo "Import\n";
$db = Db::connect($config['db_admin']);
$db->query('DROP TABLE IF EXISTS postcodes, postcodes_new, postcodes_old');
$res = (new Importer($db, $log, $config))->importZip("$tmp/a.zip");
check('rows loaded = valid unique rows (' . ($n + 2 - 0) . ')', $res['rows'] === $n + 2 + 1, json_encode($res));
check('2 data files used, Doc/ ignored', $res['files'] === 2);
check('live table exists, staging gone', !(bool) $db->query("SHOW TABLES LIKE 'postcodes_new'")->num_rows && (bool) $db->query("SHOW TABLES LIKE 'postcodes'")->num_rows);
check('header, junk and quality-90 rows skipped', $res['skipped'] === 3, (string) $res['skipped']);

$repo = new PostcodeRepository(Db::connect($config['db_read']));
echo "Feed queries\n";
$r = $repo->byPostcode('AB101AA');
check('postcode lookup finds one row', count($r) === 1);
check('stored with space as OS gave it', ($r[0]['Postcode'] ?? '') === 'AB10 1AA', json_encode($r));
check('lookup shape', array_keys($r[0]) === ['Postcode', 'Quality', 'Distance', 'Easting', 'Northing', 'Square', 'Gridref']);
check('square and gridref set', $r[0]['Square'] === 'NJ' && $r[0]['Gridref'] === 'NJ9425106376', $r[0]['Gridref']);
check('unknown postcode gives empty list', $repo->byPostcode('ZZ99ZZZ') === []);
check('injection string is just not found', $repo->byPostcode('X" OR "1"="1') === []);

$near = $repo->near(392000, 803000, 1000, 1000);
$dists = array_column($near, 'Distance');
$sorted = $dists;
sort($sorted);
check('near finds rows, closest first', count($near) > 10 && $dists === $sorted);
check('all within circle', max($dists) <= 1000);
check('exact point is 0m away', $repo->near(394251, 806376, 100, 5)[0]['Distance'] == 0.0);
check('maxpoints limits', count($repo->near(394251, 806376, 5000, 5)) === 5);
check('Shetland row present', count($repo->near(444000, 1141000, 100, 10)) === 1);

// compare against brute force in PHP
$all = $db->query('SELECT easting e, northing n FROM postcodes');
$expect = 0;
foreach ($all as $row) {
    if (hypot($row['e'] - 392000, $row['n'] - 803000) <= 1500) {
        $expect++;
    }
}
check("circle count matches brute force ($expect)", count($repo->near(392000, 803000, 1500, 1000)) === $expect);

$plan = $db->query('EXPLAIN SELECT postcode FROM postcodes WHERE easting BETWEEN 1 AND 2 AND northing BETWEEN 1 AND 2')->fetch_assoc();
check('nearby query uses the easting/northing index', ($plan['key'] ?? '') === 'en', json_encode($plan));
$plan = $db->query("EXPLAIN SELECT postcode FROM postcodes WHERE postcode_key = 'AB101AA'")->fetch_assoc();
check('postcode query uses the primary key', ($plan['key'] ?? '') === 'PRIMARY');

echo "Safety checks\n";
makeZip("$tmp/small.zip", ['Data/CSV/x.csv' => "\"AB1 1AA\",10,390000,800000\n"]);
$cfg2 = $config;
$cfg2['min_rows_ratio'] = 0.9;
$before = (int) $db->query('SELECT COUNT(*) FROM postcodes')->fetch_row()[0];
try {
    (new Importer($db, $log, $cfg2))->importZip("$tmp/small.zip");
    check('suspiciously small release is refused', false);
} catch (RuntimeException $e) {
    check('suspiciously small release is refused', true);
}
check('live table untouched after refusal', (int) $db->query('SELECT COUNT(*) FROM postcodes')->fetch_row()[0] === $before);
makeZip("$tmp/notcpo.zip", ['readme.txt' => 'nope']);
try {
    (new Importer($db, $log, $config))->importZip("$tmp/notcpo.zip");
    check('wrong kind of zip is refused', false);
} catch (RuntimeException $e) {
    check('wrong kind of zip is refused', true);
}

echo "Re-import replaces data (dropped postcodes disappear)\n";
makeZip("$tmp/b.zip", ['Data/CSV/ab.csv' => "\"AB10 1AA\",10,394251,806376\n\"AB10 1AB\",10,394300,806400\n"]);
$cfgSmallOk = $config;
$cfgSmallOk['min_rows_ratio'] = 0.0;   // operator accepts a much smaller release
(new Importer($db, $log, $cfgSmallOk))->importZip("$tmp/b.zip");
check('only new rows remain', (int) $db->query('SELECT COUNT(*) FROM postcodes')->fetch_row()[0] === 2);

// cleanup
$db->query('DROP TABLE IF EXISTS postcodes, postcodes_new, postcodes_old, import_meta');
array_map('unlink', glob("$tmp/*.zip"));
array_map('unlink', glob("$tmp/logs/*"));
@rmdir("$tmp/logs");
@rmdir($tmp);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
