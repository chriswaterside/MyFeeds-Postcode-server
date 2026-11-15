<?php
declare(strict_types=1);

// Postcode feed
//   index.php?postcode=AB113DF
//   index.php?easting=394120&northing=806540&dist=1&maxpoints=1000   (dist in km)
// See README.md.

require __DIR__ . '/bootstrap.php';

use RaPostcode\Db;
use RaPostcode\Logger;
use RaPostcode\PostcodeRepository;

ini_set('display_errors', '0');

function ra_json(int $status, $body, int $cacheSeconds = 0): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header($cacheSeconds > 0 ? "Cache-Control: public, max-age=$cacheSeconds" : 'Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Strict whole-number parse of a query parameter; null if absent, false if present but invalid. */
function ra_int(string $name): int|false|null
{
    if (!isset($_GET[$name])) {
        return null;
    }
    $v = $_GET[$name];
    return is_string($v) && preg_match('/^\d{1,9}$/', $v) ? (int) $v : false;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
    ra_json(405, ['error' => 'Method not allowed']);
}

try {
    $config = ra_load_config();
} catch (Throwable $e) {
    error_log('[postcodes] ' . $e->getMessage());
    ra_json(503, ['error' => 'Service not configured']);
}

// --- validate input before touching the database ---------------------------
$mode = null;
if (isset($_GET['postcode'])) {
    $pc = is_string($_GET['postcode']) ? PostcodeRepository::normalise($_GET['postcode']) : '';
    if (!PostcodeRepository::isValidPostcode($pc)) {
        ra_json(400, ['error' => 'postcode is not a valid UK postcode']);
    }
    $mode = 'postcode';
} else {
    $easting = ra_int('easting');
    $northing = ra_int('northing');
    if ($easting === null || $northing === null) {
        ra_json(400, ['error' => 'Supply postcode, or easting and northing']);
    }
    if ($easting === false || $northing === false || $easting > 700000 || $northing > 1300000) {
        ra_json(400, ['error' => 'easting/northing must be whole numbers within the OS National Grid']);
    }
    $distKm = $_GET['dist'] ?? $config['default_distance_km'];
    if (!is_numeric($distKm) || (float) $distKm <= 0 || (float) $distKm > $config['max_distance_km']) {
        ra_json(400, ['error' => 'dist must be a number of km, greater than 0 and at most ' . $config['max_distance_km']]);
    }
    $max = ra_int('maxpoints') ?? (int) $config['default_points'];
    if ($max === false || $max < 1 || $max > $config['max_points']) {
        ra_json(400, ['error' => 'maxpoints must be between 1 and ' . $config['max_points']]);
    }
    $mode = 'near';
}

// --- query ------------------------------------------------------------------
try {
    $db = Db::connect($config['db_read']);
    $repo = new PostcodeRepository($db);
    $rows = $mode === 'postcode'
        ? $repo->byPostcode($pc)
        : $repo->near($easting, $northing, (int) round((float) $distKm * 1000), $max);
    $db->close();
} catch (Throwable $e) {
    // Log only; do not email per request (a DB outage would send thousands of mails)
    (new Logger($config['log_dir'], (int) $config['log_keep_days']))->error('Feed query failed: ' . $e->getMessage());
    ra_json(503, ['error' => 'Postcode service temporarily unavailable']);
}

ra_json(200, $rows, (int) $config['cache_seconds']);
