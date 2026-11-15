<?php
declare(strict_types=1);

// Postcode importer. Run from cron (CLI only):
//   php task.php                 check OS for a new Code-Point Open release; import it if found
//   php task.php --force         import even if the release was already imported
//   php task.php --zip=FILE      import a zip you downloaded yourself (no network needed)
//   php task.php --show-skipped  also log the first few rows skipped in each file
//   php task.php --test-email    send a test message and show the conversation with the mail server
//   php task.php --help

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

use RaPostcode\Db;
use RaPostcode\Importer;
use RaPostcode\Logger;
use RaPostcode\Notifier;
use RaPostcode\OsDownloader;
use RaPostcode\Schema;

date_default_timezone_set('Europe/London');
$opts = getopt('', ['force', 'zip:', 'show-skipped', 'test-email', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php task.php [--force] [--zip=FILE] [--show-skipped] [--test-email]\n";
    exit(0);
}

try {
    $config = ra_load_config();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$log = new Logger($config['log_dir'], (int) $config['log_keep_days'], true);
$notify = new Notifier($config, $log);

if (isset($opts['test-email'])) {
    $to = $config['notify_to'] ?: '(notify_to is not set)';
    echo "Sending a test email to $to ...\n";
    $ok = $notify->send('Test message', "This is a test from task.php --test-email.\nIf you can read this, update emails will reach you.", true);
    echo $ok ? "\nThe mail server accepted the message. If it does not arrive, check spam/junk and the mail server's own logs.\n"
             : "\nSending failed. See the messages above.\n";
    exit($ok ? 0 : 1);
}

if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0750, true)) {
    $log->error('Cannot create data dir ' . $config['data_dir']);
    exit(2);
}
$lock = fopen($config['data_dir'] . '/import.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log->info('Another import is already running; exiting');
    exit(0);
}

$tmpZip = null;
$exitCode = 0;
try {
    $db = Db::connect($config['db_admin']);
    Schema::ensureMeta($db);

    if (isset($opts['zip'])) {
        $zipPath = (string) $opts['zip'];
        if (!is_file($zipPath)) {
            throw new RuntimeException("Zip not found: $zipPath");
        }
        $release = md5_file($zipPath);
    } else {
        $downloader = new OsDownloader($config['os_download_url'], $log, $config['ca_bundle'] ?? null);
        $meta = $downloader->fetchMetadata();
        $release = $meta['md5'];
        if ($release !== '' && !isset($opts['force']) && Schema::getMeta($db, 'release_md5') === $release) {
            $log->info("Release $release is already imported; nothing to do");
            $db->close();
            exit(0);
        }
        $zipPath = $tmpZip = $config['data_dir'] . '/codepo_gb.zip';
        $downloader->download($meta, $zipPath);
    }

    if (!isset($opts['force']) && $release !== '' && Schema::getMeta($db, 'release_md5') === $release) {
        $log->info("Release $release is already imported; nothing to do");
        throw new RaPostcode\NothingToDo();
    }

    $started = microtime(true);
    $result = (new Importer($db, $log, $config + ['show_skipped' => isset($opts['show-skipped'])]))->importZip($zipPath);
    Schema::setMeta($db, 'release_md5', $release);
    Schema::setMeta($db, 'imported_at', date('c'));
    Schema::setMeta($db, 'row_count', (string) $result['rows']);
    $secs = round(microtime(true) - $started);

    $summary = "Imported {$result['rows']} postcodes from {$result['files']} files in {$secs}s "
        . "({$result['skipped']} rows skipped). Release checksum $release.";
    $log->info($summary);
    $notify->send('Postcode data updated', $summary);
    $db->close();
} catch (RaPostcode\NothingToDo $e) {
    // already up to date: not an error
} catch (Throwable $e) {
    $log->error('Import failed: ' . $e->getMessage());
    $notify->send('Postcode import FAILED', $e->getMessage() . "\n\nThe live postcode table has not been changed.");
    $exitCode = 1;
} finally {
    if ($tmpZip && is_file($tmpZip)) {
        @unlink($tmpZip);
    }
    flock($lock, LOCK_UN);
}
exit($exitCode);
