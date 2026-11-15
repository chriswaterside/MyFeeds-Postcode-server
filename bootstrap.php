<?php
declare(strict_types=1);

// Shared start-up for index.php and task.php.

define('RA_ROOT', __DIR__);

spl_autoload_register(function (string $class): void {
    $prefix = 'RaPostcode\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = RA_ROOT . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Load config.php. There is deliberately no fallback to test credentials:
 * a missing config is an error.
 */
function ra_load_config(): array
{
    $file = getenv('RA_POSTCODE_CONFIG') ?: RA_ROOT . '/config.php';
    if (!is_file($file)) {
        throw new RuntimeException('config.php not found. Copy config.sample.php to config.php and edit it.');
    }
    $config = require $file;
    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array.');
    }
    // sample values act as defaults for anything not set
    $defaults = require RA_ROOT . '/config.sample.php';
    return array_replace($defaults, $config);
}
