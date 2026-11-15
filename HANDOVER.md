# Ramblers Postcode Feed: Hand-over Note

14 November 2026. For the technical setup, see `README.md`.

## What this is

The postcode feed is a small web service that turns a UK postcode into a map location, and finds the postcodes nearest to a point. Other Ramblers websites call it in the background, so most people never see it.

Two parts make it work:

- **The lookup** answers requests such as "where is this postcode?" and "which postcodes are within 1 km of here?". It runs all the time.
- **The updater** keeps the postcode list current. It runs by itself on a schedule and normally needs no attention.

If the service stops answering, anything that relies on it (for example, a postcode search on a Ramblers site) stops working too. Everything else on those sites carries on.

## Where the data comes from

All postcode locations come from **Code-Point Open**, a free dataset published by Ordnance Survey (OS), the national mapping agency. It lists about 1.75 million postcodes in Great Britain with a grid position for each.

- **New releases:** OS publishes an updated version four times a year, in February, May, August and November.
- **Cost:** none. OS lets anyone use it, including for commercial work, under its OpenData licence.
- **Coverage:** England, Scotland and Wales. The November 2026 release has no files for Northern Ireland (BT), the Channel Islands (GY, JE) or the Isle of Man (IM), so those postcodes are not found.
- **Attribution:** the licence requires a credit line wherever the data is shown to the public. The exact wording is in `licence.txt`, inside the OS download. Check it and make sure the Ramblers sites that use the feed carry it.

## How it keeps itself up to date

A scheduled task runs once a week (suggested: early Monday morning). Most weeks it finds nothing new and stops within seconds. When OS publishes a new release, it does the rest on its own:

1. Asks OS for the current release and compares its fingerprint (a checksum) with the one it imported last time. If they match, it stops.
2. Downloads the file, about 14 MB, and checks that it arrived intact.
3. Loads every postcode into a separate, hidden copy of the list.
4. Checks the copy looks sensible: at least 1 million postcodes, and not more than 10% fewer than the current list.
5. Swaps the new list in with one instant switch, so visitors never see a half-updated list.
6. Emails a summary to the notification address.

If any step fails, the old list stays in place and carries on working. A failure sends an email saying so.

Postcodes that OS has removed disappear at the next update. New ones appear the same way.

## Is it working?

**A one-minute check.** Open the lookup in a browser with a postcode you know, for example `https://<your site>/index.php?postcode=<a postcode>`. A short block of text comes back with that postcode, a grid reference and a location. An empty list or an error means something needs looking at (see the next section).

**After each quarterly update,** the summary email (or the newest file in the `logs` folder) should look like this:

| What to look at | Healthy | Worth a closer look |
| --- | --- | --- |
| Files read | 120 | Fewer than 120 |
| Postcodes loaded | About 1.75 million (1,748,497 in November 2026) | Under 1.7 million, or a sudden jump |
| Rows skipped | Around 900 (888 in November 2026) | Thousands |
| Last line | "New postcode table is live" | A line starting ERROR, or no update for over four months |

The skipped rows are postcodes OS lists without a location, so they cannot be placed on a map. They are skipped on purpose.

## If something goes wrong

A failed update never breaks the live service, so there is rarely any rush. Match what you see to a row, then pass the details to whoever supports the server.

| What you see | What it probably means | What to do |
| --- | --- | --- |
| Lookup shows "Postcode service temporarily unavailable" or "Service not configured" | The database is down, or its settings file is missing or wrong | Tell support straight away. The websites that use the feed will show no postcode results until it is fixed. |
| An email headed "Postcode import FAILED" | The update stopped and the old list is still live | Forward the email to support. It says why. Phrases such as "refusing to replace" mean the OS file looked wrong. "Certificate problem" or "Access denied" are server settings. |
| No update email or log file for many weeks | The weekly scheduled task has stopped running | Ask support to check the scheduled task on the server. |
| One ordinary postcode returns nothing | It may be newer than the last OS release, or one of the postcodes OS lists without a location | Try a neighbouring postcode. If most postcodes still work, no action is needed. New ones appear at the next quarterly update. |
| Everything stopped after a server move or upgrade | Settings or scheduled tasks did not move with it | Tell support. The reference section below lists what needs recreating. |

**What to pass on:** the exact wording of the error, the date and time you saw it, and the newest file from the `logs` folder.

## Questions you may be asked

**Does it cover Northern Ireland?** No. The OS data covers Great Britain only. Adding Northern Ireland would need a second data source and some development work.

**Is it free to run, and is anything personal stored?** The data is free. The service stores only each postcode, its position and a quality code. It holds no personal information about visitors or members.

**Who can use it?** Anyone who knows the address. It is open to any website and needs no password, so the limits below protect it from being overloaded.

**What are the limits?** A search can reach up to 20 km from a point and return up to 1,000 postcodes. Without options, a search covers 1 km and returns the single nearest postcode. A request outside these limits gets a short error message rather than a result.

**Why can a change take a day to show?** Browsers and caches keep each answer for up to 24 hours, to keep the service fast. New postcodes only arrive quarterly anyway, so this rarely matters.

**Can we update outside the quarterly cycle?** The weekly check already picks up any new OS release. Support can also force an update on request.

## Reference for whoever supports it

The full technical instructions are in `README.md`, inside the project folder (version 2.0.0). Fill in the local details below when this note is adopted, so the next person does not have to hunt for them.

| Item | Detail |
| --- | --- |
| Website address of the lookup | *fill in* |
| Server and folder it lives in | *fill in* |
| Database name | *fill in* |
| Database users | One read-only user for the lookup, one update user for the updater (their passwords are in `config.php`, never in this note) |
| Weekly scheduled task | *fill in: name, time, and the account it runs under* |
| Email address that receives update summaries | *fill in* |
| Who supports it | *fill in: name and contact* |
| Last reviewed | *fill in* |

**What lives where**

- `index.php`: the lookup that websites call.
- `task.php`: the updater. It only runs from the command line, never from a web page.
- `config.php`: private settings (database logins, limits, email). Keep it out of version control and out of the public web folder.
- `logs/`: one log per day, kept 30 days. `data/`: temporary working files.

**Commands** (run from the project folder)

- `php task.php` checks for a new OS release and imports it if there is one. This is what the weekly task runs.
- `php task.php --force` imports again even if nothing changed.
- `php task.php --zip=FILE` imports a Code-Point Open zip that was downloaded by hand.
- `php task.php --show-skipped` also lists examples of the rows it skipped.
- `php tests/run_tests.php --config=FILE` runs the automated tests against a spare database whose name contains "test". It refuses to run against anything else.

**Recreate after a server move:** the project files, `config.php`, the two database users, the database itself (the first run of `php task.php` loads the data), and the weekly scheduled task. On Windows, `config.php` also needs the `ca_bundle` path so the server can verify the OS website, and the `smtp` block if update emails are wanted.

OS download address: `https://api.os.uk/downloads/v1/products/CodePointOpen/downloads?area=GB&format=CSV`
