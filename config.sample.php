<?php
// Copy this file to config.php and edit. config.php must NOT be committed or web-readable.
// Keep config.php (and data/ and logs/) outside the web root if you can.

return [
    // Used by index.php (the public feed). Give this user SELECT only.
    'db_read' => [
        'host' => 'localhost',
        'name' => 'postcodes',
        'user' => 'postcodes_ro',
        'pass' => 'change-me',
    ],
    // Used by task.php (the importer). Needs SELECT, INSERT, UPDATE, CREATE, DROP, ALTER on the database.
    'db_admin' => [
        'host' => 'localhost',
        'name' => 'postcodes',
        'user' => 'postcodes_rw',
        'pass' => 'change-me-too',
    ],

    // Feed limits
    'max_distance_km' => 20,
    'default_distance_km' => 1,
    'max_points' => 1000,
    'default_points' => 1,
    'cache_seconds' => 86400,        // Cache-Control max-age for responses

    // Importer
    'os_download_url' => 'https://api.os.uk/downloads/v1/products/CodePointOpen/downloads?area=GB&format=CSV',
    // Path to a CA certificate bundle (cacert.pem) if PHP's curl cannot verify HTTPS sites, as on Windows/WAMP.
    // Download it from https://curl.se/docs/caextract.html. Use forward slashes, e.g. 'D:/wamp64/cacert.pem'.
    // Leave null on servers where PHP already has a bundle.
    'ca_bundle' => null,
    'data_dir' => __DIR__ . '/data',   // temp download + lock + state
    'log_dir' => __DIR__ . '/logs',
    'log_keep_days' => 30,
    'min_rows_ratio' => 0.90,         // refuse to swap in a table smaller than 90% of the current one
    'min_rows_absolute' => 1000000,   // ...or smaller than this (first import)

    // Email notifications (importer only)
    'notify_to' => '',   // REQUIRED for emails: who receives update summaries and failure alerts
    'mail_from' => '',   // REQUIRED for emails: use an address the SMTP account is allowed to send as
    'mail_from_name' => 'Ramblers Postcode Feed',
    // Leave 'smtp' as null to use PHP mail(); otherwise fill in:
    'smtp' => null,
    // 'smtp' => ['host' => 'smtp.example.org', 'port' => 587, 'secure' => 'tls', 'user' => '', 'pass' => ''],
];
