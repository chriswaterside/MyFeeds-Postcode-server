# Ramblers postcode feed server (v2)

Serves UK postcode locations as JSON, and keeps its data up to date by itself from
OS **Code-Point Open** (GB postcodes, Open Government Licence / OS OpenData licence).

For a plain-English overview aimed at non-technical colleagues, see `HANDOVER.md`.

## The feed

    index.php?postcode=AB113DF
    index.php?easting=394120&northing=806540&dist=1&maxpoints=1000

| parameter  | meaning                                   | default | limit (config.php)      |
|------------|-------------------------------------------|---------|-------------------------|
| postcode   | a postcode, with or without space/any case |         | must look like a UK postcode |
| easting, northing | OS National Grid, whole metres     | required | 0–700000 / 0–1300000    |
| dist       | search radius in km                       | 1       | `max_distance_km` (20)  |
| maxpoints  | maximum postcodes returned, nearest first | 1       | `max_points` (1000)     |

Response: a JSON array, same fields as v1:

    [{"Postcode":"AB10 0AA","Quality":10,"Distance":0,"Easting":384402,"Northing":808651,"Square":"NJ","Gridref":"NJ8440208651"}]

`Distance` is metres from the search point (0 for a postcode lookup). Responses carry
`Cache-Control: public, max-age=86400` and `Access-Control-Allow-Origin: *`.

**Changes from v1 that callers might notice**

* Bad input now gets HTTP **400** with `{"error":"…"}` (v1 returned `[]` with 200).
* Database trouble gets HTTP **503** with `{"error":"…"}`. No email is sent per request.
* `dist` and `maxpoints` are capped (v1 had no limit).
* Postcodes OS publishes with no coordinates (positional quality 90, easting/northing 0) are
  not loaded, so they are simply "not found" rather than returned at grid square SV.
* Postcodes OS has stopped publishing disappear at the next release (v1 kept them up to 60 days).

## Install

Requirements: PHP 8.1+ with `mysqli`, `curl`, `zip`; MySQL 5.7+/MariaDB 10.3+.
PHPMailer 7.2.0 is bundled in `classes/vendor/phpmailer` (no Composer needed).

1. Upload the folder. Put it outside the web root if you can, with only `index.php` reachable
   (the supplied `.htaccess` files deny everything else on Apache; on nginx deny it yourself).
2. Create the database and **two** users:
   * `postcodes_ro`: `SELECT` only (used by `index.php`)
   * `postcodes_rw`: `SELECT, INSERT, UPDATE, CREATE, DROP, ALTER` on that database only (used by `task.php`)
3. `cp config.sample.php config.php` and fill in the database details, mail addresses and,
   if you want SMTP rather than PHP `mail()`, the `smtp` block.
   There is **no fallback config**: if `config.php` is missing the feed returns 503.
4. Load the data once, from the command line: `php task.php`
   (downloads about 14 MB, imports about 1.7 million postcodes; allow a few minutes).
5. Add a cron job (weekly is plenty, a check costs one small request):

       17 4 * * 1  cd /path/to/mypostcode-server && php task.php

## How the importer works (`task.php`, command line only)

1. Asks the OS Downloads API (no key needed) for the current Code-Point Open CSV release:
   `https://api.os.uk/downloads/v1/products/CodePointOpen/downloads?area=GB&format=CSV`
   (without `&redirect` this returns JSON with the file's md5 and size).
2. If that md5 matches the release already imported, it stops. Otherwise it downloads the zip
   and checks size and md5.
3. Reads `Data/CSV/*.csv` straight out of the zip into a new table `postcodes_new`
   (batched prepared inserts), skipping headers, junk and no-coordinate rows.
4. Refuses to continue if the new table is smaller than `min_rows_absolute`, or smaller than
   `min_rows_ratio` of the live table.
5. Swaps tables with one atomic `RENAME TABLE`, so the feed never sees a half-loaded table.
6. Emails a summary (and emails on failure; the live table is never touched on failure).

Options: `--force` (import even if already imported), `--zip=FILE` (import a zip you downloaded
yourself, no network), `--show-skipped` (log up to 5 skipped rows per file), `--test-email` (send a test message and show the conversation with the mail server), `--help`. The log has one line per CSV file with rows loaded and skipped. A lock file stops overlapping runs. Logs are in `logs/`
(kept `log_keep_days` days). OS publishes Code-Point Open quarterly (Feb, May, Aug, Nov).

## Upgrading from v1

The v1 table has a different layout, so the feed will not work against it. Run
`php task.php` once (step 4) before pointing traffic at v2; the importer builds the new table
beside the old one and replaces it. You no longer need the `postcodefiles/` folder or manual
unzipping.

## Tests

Command line only (not NetBeans' "Test Project" or a browser; the suite is a plain PHP script, not PHPUnit).

1. Create a scratch database whose name contains `test`, e.g. `postcodes_test`, and grant the importer user
   `SELECT, INSERT, UPDATE, CREATE, DROP, ALTER` on it (and the read-only user `SELECT`).
2. Copy `config.sample.php` to `test-config.php` and point both `db_read` and `db_admin` at that database.
3. Run:

       php tests/run_tests.php --config=/full/path/to/test-config.php

The tests drop and recreate the `postcodes` table, so the script **refuses to run** against any database
whose name does not contain "test", and refuses to run through a web server.

Covers grid references, postcode validation, import (skips, duplicates, safety refusals, re-import),
lookups, distance ordering against a brute-force check, and index use.

In NetBeans: Project Properties > Run Configuration > Run As "Script (run in command line)", set the PHP
interpreter to your `php.exe`, Index File `tests/run_tests.php`, Arguments `--config=D:/path/to/test-config.php`.

## Licence and attribution

Code-Point Open contains OS, Royal Mail and National Statistics data. Check `Doc/licence.txt`
in the OS download and include the attribution it requires wherever this data is displayed.
PHPMailer is LGPL 2.1 (see `classes/vendor/phpmailer/LICENSE`).

## Setting up the database users (phpMyAdmin → SQL tab, logged in as root)

    CREATE DATABASE IF NOT EXISTS postcodes CHARACTER SET utf8mb4;
    CREATE USER 'postcodes_ro'@'localhost' IDENTIFIED BY 'a-long-password';
    CREATE USER 'postcodes_rw'@'localhost' IDENTIFIED BY 'another-long-password';
    GRANT SELECT ON postcodes.* TO 'postcodes_ro'@'localhost';
    GRANT SELECT, INSERT, UPDATE, CREATE, DROP, ALTER ON postcodes.* TO 'postcodes_rw'@'localhost';

Grant on the database (`postcodes.*`), not globally. If login fails with "Access denied" even though
the password is right, look for **anonymous users** (blank user name, shown as "Any" in phpMyAdmin).
An anonymous `''@'localhost'` entry takes priority over `user@'%'` for local connections, so the
real user never matches. Remove it: `DROP USER ''@'localhost';` (and `''@'<your-hostname>'`).
On shared hosting, create the database and users in the hosting control panel instead and
assign the privileges above to the database.

## Running on Windows / WAMP

* **"SSL certificate problem: unable to get local issuer certificate"**: PHP on Windows has no list of
  trusted certificate authorities. Download `cacert.pem` from https://curl.se/docs/caextract.html, save it
  somewhere (e.g. `D:/wamp64/cacert.pem`) and set `'ca_bundle' => 'D:/wamp64/cacert.pem'` in `config.php`.
  (Alternatively set `curl.cainfo` in the CLI `php.ini`.) Never disable certificate checking.
* **"Could not instantiate mail function"**: Windows has no `sendmail`, so PHP `mail()` cannot send.
  Fill in the `smtp` block in `config.php` (host, port, secure, user, pass), or ignore the message while testing:
  it is logged but does not stop the import.
* Run it with the CLI PHP, e.g. `php.exe task.php`. For a scheduled task use Windows Task Scheduler.

## Email not arriving

Run `php task.php --test-email`. It prints the full conversation with the mail server. Remember that
`notify_to` and `mail_from` must both be set in `config.php`, and that **no email is sent when there is
nothing to do** (release already imported): success emails only follow a real import, failure emails follow a failed one.

* **The log says "Email accepted by SMTP server…"** The server took the message, so the problem is after the hand-over:
  check the spam/junk folder, make sure `notify_to` is the address you are reading, and look at the sending
  account's "sent" items or the provider's mail log.
* **`mail_from` is not an address the SMTP account may send as.** Many providers silently rewrite it, drop it or mark it
  as junk. Use the SMTP account's own address, or one it is allowed to send as.
* **Sending from a domain whose DNS (SPF/DKIM) does not authorise your mail server** often goes to junk. Sending through
  the mailbox's own provider avoids this.
* **Errors starting `SMTP Error`** are shown in full by `--test-email`: wrong password, wrong port or the wrong `secure` value
  (`tls` for 587, `ssl` for 465).
