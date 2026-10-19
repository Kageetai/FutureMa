# FutureMa

A private letter to your future self. Write in a browser, choose a date, whole
hour and IANA time zone, and receive it at one configured email address. PHP,
SQLite and one hourly cron job; no JavaScript, Composer, npm or web daemon.

The form has no archive or reading endpoint. Letters and backups remain readable
to the hosting account. Mail acceptance is not proof of inbox arrival. Monitor
the envelope sender’s mailbox for bounces and check Spam. A crash between mail
acceptance and the database update can cause a duplicate; stable Message-IDs
help investigate but do not guarantee deduplication. Submitted letters are kept.

## Requirements and verification status

PHP **8.4+**, PDO SQLite, SQLite3 (backups), mbstring, sessions, POSIX and PCNTL
(CLI transport isolation), `proc_open`, and
`flock`; Apache with authentication, rewrite, environment and `.htaccess`
support; HTTPS; local sendmail; cron. Use a Unix-like host. CLI and web PHP must
access the same private configuration, database, session storage and lock path.

The original hosting probes used Uberspace **7**, PHP **8.4.26**, on 17 October
2026. They verified local mail and bounce delivery, HTTPS, authentication, cron,
SQLite and private paths, but were not a deployment of this application. See
[the implementation record](docs/IMPLEMENTATION_STATUS.md) for current checks
and outstanding deployment work. U8 compatibility is untested.

Local checks use fake transports and a temporary HTTP server. They cover Unicode
request limits, CSRF, authentication boundaries, persistence, DST gaps/folds,
UTC schedules, retries, process deadlines, backups and recovery. The test router
simulates Apache’s authenticated-user boundary; live Apache checks remain needed.

```sh
php tests/check.php
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

Expected: `PASS: ... checks`, syntax checks succeed, exit 0. Deliberate fake
transport failures print safe diagnostics to stderr. Tests do not send email.
They need localhost listening permission. On macOS with Homebrew PHP 8.4, use
`/opt/homebrew/opt/php@8.4/bin/php` if it is not on PATH.

## Install on Uberspace 7

Run host commands over SSH as the account owner. Replace `ACCOUNT`, `SITE`, and
the example email addresses with your values. The repository URL is
`https://github.com/Kageetai/FutureMa.git`; use the implementation branch until
it is merged and published. Keep existing sites and cron entries. Do not put
the repository itself in a document root.

1. Verify the account generation in the Uberspace dashboard. Select a supported
   PHP 8.4+ version with `uberspace tools version use php 8.4` only after checking
   other applications on the account. The earlier account check already selected
   8.4. Avoid inheriting a legacy `PHPRC`:

   ```sh
   /usr/bin/env -u PHPRC /usr/bin/php -v
   /usr/bin/env -u PHPRC /usr/bin/php -m
   command -v sendmail
   date '+%Z %z'
   crontab -l
   ```

2. Pick a dedicated subdomain; configure its DNS to this host and register it:

   ```sh
   uberspace web domain list
   uberspace web domain add SITE
   ```

   Skip `add` if already registered. Uberspace manages HTTPS; verify a valid
   certificate before sending any credentials. The actual site for this project
   is `futurema.kageetai.net`.

3. Prepare private code and storage. Clone only if the destination does not
   already exist:

   ```sh
   umask 077
   git clone https://github.com/Kageetai/FutureMa.git "$HOME/futurema"
   cd "$HOME/futurema"
   mkdir -p var/backups
   chmod 700 "$HOME/futurema" var var/backups
   cp config.example.php config.php
   chmod 600 config.php
   ```

   Edit `config.php`: set the absolute database path to
   `/home/ACCOUNT/futurema/var/letters.sqlite`, your recipient, and the required
   envelope sender. Use a mailbox you actually monitor. No SMTP credentials are
   needed. The default visible identity resolves to the OS username at the
   machine hostname. Verify it with `php -r 'require "app.php";
   echo FutureMa\config()["visible_sender"], PHP_EOL;'` and in received mail.
   It is independent of the envelope sender.

4. Initialize and run the checks from the private repository:

   ```sh
   /usr/bin/env -u PHPRC /usr/bin/php manage.php init
   /usr/bin/env -u PHPRC /usr/bin/php tests/check.php
   /usr/bin/env -u PHPRC /usr/bin/php manage.php status
   ```

   Expect `Database ready.`, a passing suite, and `[]` for a fresh database.

5. Prepare authentication outside the document root. Apache cannot traverse
   the private home directory on the tested account. Its password file must
   instead be Apache-readable under `/var/www/virtual/ACCOUNT/`:

   ```sh
   mkdir -p /var/www/virtual/ACCOUNT/futurema-auth
   chmod 755 /var/www/virtual/ACCOUNT/futurema-auth
   htpasswd -cB /var/www/virtual/ACCOUNT/futurema-auth/.htpasswd owner
   chmod 644 /var/www/virtual/ACCOUNT/futurema-auth/.htpasswd
   ```

   `htpasswd` prompts locally; never put the password on a command line. Run
   `-c` only for a new password file. Preserve this file during updates. Verify
   its path is outside every served document root and its SELinux context is
   appropriate (`restorecon` if required by the host).

6. Publish **only** `public/` into the dedicated subdomain document root
   `/var/www/virtual/ACCOUNT/SITE`. If that directory exists, inspect it and
   preserve its files before replacing the placeholder. Do not touch `html/`
   or other sites. Copy rather than symlink private home-directory files:

   ```sh
   mkdir -p /var/www/virtual/ACCOUNT/SITE
   cp public/index.php public/.htaccess public/.user.ini /var/www/virtual/ACCOUNT/SITE/
   chmod 755 /var/www/virtual/ACCOUNT/SITE
   chmod 644 /var/www/virtual/ACCOUNT/SITE/index.php /var/www/virtual/ACCOUNT/SITE/.htaccess /var/www/virtual/ACCOUNT/SITE/.user.ini
   ```

   Edit the **published** `.htaccess`, replacing both `ACCOUNT` paths. The
   `FUTUREMA_APP` environment variable must point to the private `app.php`.
   The public PHP file loads private configuration beside that file. PHP must
   receive Apache’s authenticated `REMOTE_USER`; the app deliberately rejects
   requests with only an unverified `PHP_AUTH_USER` value.

   `.user.ini` sets `post_max_size=3M`, above the application’s 2 MiB encoded
   request limit. Apache and PHP must both permit the complete 2 MiB request.
   PHP-FPM may cache `.user.ini` for several minutes; verify the effective
   setting. PHP errors must stay out of browser responses. Keep any host PHP
   error logs private and avoid logging request bodies.

7. Verify externally using HTTPS. `curl -u owner` prompts for the password:

   ```sh
   curl -I https://SITE/
   curl -i -X POST https://SITE/
   curl -u owner -I https://SITE/
   curl -u owner -I https://SITE/config.php
   curl -u owner -I https://SITE/var/letters.sqlite
   curl -u owner -I https://SITE/.user.ini
   ```

   The first two must return 401; authenticated HEAD returns 405 (only GET/POST
   are supported). For the form use `curl -u owner https://SITE/` or a browser.
   Private paths must not return file contents; dot files must be denied.
   Confirm HTTP cannot accept submissions. Check a phone-sized viewport and
   keyboard navigation, invalid CSRF, a maximum Unicode letter, and failed writes.
   Never leave a public diagnostics or `phpinfo()` page behind.

8. Before enabling the site for ordinary use, create a **separate smoke database
   and private config**, then route both the real form and sender to it:

   ```sh
   cp config.php var/smoke-config.php
   chmod 600 var/smoke-config.php
   ```

   Edit the smoke config's `database` to
   `/home/ACCOUNT/futurema/var/smoke.sqlite`. Append
   `SetEnv FUTUREMA_CONFIG /home/ACCOUNT/futurema/var/smoke-config.php` to the
   published `.htaccess`. Initialize using the selected config:

   ```sh
   FUTUREMA_CONFIG=/home/ACCOUNT/futurema/var/smoke-config.php /usr/bin/env -u PHPRC /usr/bin/php manage.php init
   ```

   Submit one harmless letter in the real form, then verify `manage.php status`
   using the smoke config. Only after confirming it exists in that isolated DB,
   advance its due timestamp:

   ```sh
   sqlite3 /home/ACCOUNT/futurema/var/smoke.sqlite 'UPDATE letters SET due_at = 1 WHERE submitted_at IS NULL;'
   FUTUREMA_CONFIG=/home/ACCOUNT/futurema/var/smoke-config.php /usr/bin/env -u PHPRC /usr/bin/php /home/ACCOUNT/futurema/send-due.php
   FUTUREMA_CONFIG=/home/ACCOUNT/futurema/var/smoke-config.php /usr/bin/env -u PHPRC /usr/bin/php /home/ACCOUNT/futurema/manage.php status
   ```

   Never advance a real private letter. Check one received message, its visible
   From, original `Return-Path`, Spam placement and SPF/DKIM results. Rerun the
   sender and confirm no second submission. Verify a controlled local bounce to
   the configured envelope sender; original acceptance alone is insufficient.

   Rerun the exact smoke sender command and verify no second submission. Remove
   only the `SetEnv FUTUREMA_CONFIG` line from the published `.htaccess` to route
   the form back to the ordinary private config, and ensure that database is
   still empty. Keep smoke data private until deliberately removing it. Use a
   separate harmless letter for the actual cron test; do not alter its due time.

9. Preserve existing cron entries. Save `crontab -l` to a private file, then
   use `crontab -e` to **append**, with absolute paths and one transport only:

   ```cron
   0 * * * * /usr/bin/env -u PHPRC /usr/bin/php /home/ACCOUNT/futurema/send-due.php >> /home/ACCOUNT/futurema/var/sender.log 2>&1 || /usr/bin/printf 'FutureMa sender failed; inspect private sender.log\n' >&2
   ```

   Set cron `MAILTO` to a monitored mailbox if not already configured. The
   sender job is hourly at minute zero in the **host’s cron time
   zone**, not the chosen letter zone. Keep the existing `PHPRC` setting for
   unrelated jobs; only these PHP commands unset it. Watch a real hourly cron
   run and confirm a received letter. `tail var/sender.log` shows successful run
   times; nonzero exit produces a cron error without personal text. Rotate logs
   periodically; check quota and perform backups manually as described below.

10. **Backups are manual, by the owner's choice (19 October 2026).** No backup
    cron entry, LaunchAgent, or other backup automation should be installed.
    Run a consistent server snapshot before updates, before recovery/deletion,
    and whenever you want to preserve new letters:

    ```sh
    ssh kagee7ai@kageetai.net '/usr/bin/env -u PHPRC /usr/bin/php /home/kagee7ai/futurema/manage.php backup /home/kagee7ai/futurema/var/backups'
    ```

    Then copy backups off-host over SSH to a private, encrypted local disk. From
    your own computer, replace the SSH account/host and destination:

    ```sh
    mkdir -p /PRIVATE/OFFHOST/futurema
    chmod 700 /PRIVATE/OFFHOST/futurema
    rsync -a ACCOUNT@SSH_HOST:/home/ACCOUNT/futurema/var/backups/ /PRIVATE/OFFHOST/futurema/
    ```

    For the current deployment, use `kagee7ai@kageetai.net` as the SSH account
    and `/home/kagee7ai/futurema/var/backups/` as the remote source. The owner's
    chosen local destination is `/Users/Kageetai/Backups/FutureMa`; create it
    manually with mode 0700 before copying. Verify downloaded SQLite snapshots
    with `sqlite3 PATH_TO_SNAPSHOT 'PRAGMA integrity_check;'` (expect `ok`).
    Nothing runs automatically. The server retains the most recent 30 dated
    snapshots, replacing the current date's snapshot on repeat runs. Without
    manual runs, no new backups exist. Off-host retention is manual as well.
    A server-only backup is insufficient.

    Exact manual copy commands for this deployment, run on this Mac:

    ```sh
    mkdir -p /Users/Kageetai/Backups/FutureMa
    chmod 700 /Users/Kageetai/Backups/FutureMa
    rsync -a kagee7ai@kageetai.net:/home/kagee7ai/futurema/var/backups/ /Users/Kageetai/Backups/FutureMa/
    sqlite3 /Users/Kageetai/Backups/FutureMa/futurema-YYYY-MM-DD.sqlite 'PRAGMA integrity_check;'
    ```

## Configuration

All paths are absolute. Keep `config.php` private (0600). Set `FUTUREMA_CONFIG`
in the process environment only when using a separate smoke/recovery config.

| Key | Required/default | Meaning |
| --- | --- | --- |
| `database` | Required | Private SQLite path; parent directory must exist |
| `recipient` | Required | One validated email; current value used for all future handoffs |
| `envelope_sender` | Required | Bounce destination passed as its own transport argument |
| `visible_sender` | OS account + `@` + hostname | Optional validated From override; its domain forms Message-ID |
| `transport` | `sendmail` | `sendmail` or explicit `msmtp` |
| `transport_path` | `/usr/sbin/sendmail` or `/usr/bin/msmtp` | Absolute executable path; resolve on the account |
| `timeout` | `30` | Integer transport deadline, 1–120 seconds |
| `lock` | Database path + `.lock` | Same private sender lock for cron and recovery |
| `maintenance` | `maintenance` beside database | Existing marker blocks web saves and sender |
| `msmtp_config` | Required only for `msmtp` | Absolute private msmtp config path |

Minimal default-mail configuration is in [config.example.php](config.example.php).
Do not add a `Return-Path` or route bounces using `Reply-To`. Changing the
envelope sender does not change From/Message-ID. Keep the visible identity stable
to simplify later recovery, and retain mail sent to previous recipient addresses.

### Optional custom SMTP

Use only after explicitly choosing it; a default transport error never selects
SMTP automatically. Keep authentication/TLS settings in a private msmtp file:

```text
defaults
auth on
tls on
tls_starttls on
tls_certcheck on
timeout 30
account default
host smtp.example.org
port 587
user you@example.org
from bounces@example.org
passwordeval YOUR_PRIVATE_CREDENTIAL_HELPER
```

Replace the credential-helper placeholder with your chosen private helper, make
the file 0600, verify the provider permits the envelope sender, and explicitly
configure `transport`, `transport_path` and `msmtp_config`. Do not commit secrets.

## Usage and failure handling

Choose a future date, a whole hour and an IANA zone (default Europe/Berlin).
The server rejects nonexistent or repeated DST hours, past times, injected
headers and oversized text. Native browser date/hour inputs require no JS.
Confirmation appears only after committing the letter. Do not use the browser’s
UTF-16-based maxlength to count long Unicode letters; the server counts Unicode
characters. Keep a local draft until confirmation if losing a draft would matter.

Scheduling stores a fixed UTC instant and the selected zone. Travel or choosing
another zone for the next letter cannot move it. Cron submits on the first
hourly run at/after that instant, catching up after downtime. Fractional-offset
zones such as Kolkata/Kathmandu may wait until the next run. There is no early
submission and no five-letter batch cap.

A failed handoff stops the remaining queue until the next run. The verified U7
sendmail rate-limit signal is **exit 1** with stderr starting `You are sending too
many mails.`; it never consumes retries. Other failures, including unclassified
ones and timeouts, consume one of five failures. Exhausted letters are retained,
reported on stderr and skipped so later letters can proceed. Raw transport error
output is never persisted because it might echo personal text or credentials.

`php manage.php status` shows IDs, UTC Unix timestamps, zones and safe failure
state without printing subjects/bodies. Diagnose transport availability, private
path permissions, quota, cron `PHPRC`, bounce mailbox and Spam first. After fixing
a failure, enable maintenance and use `php manage.php retry EXACT_ID` for one
unsent letter, then resume. A later bounce never automatically causes a resend.

## Backup, restore and rollback

Backups are intentionally manual. `php manage.php backup /absolute/private/backup-dir` uses SQLite’s backup API,
checks integrity, then installs the snapshot atomically. Never copy an actively
written database as a backup. Before any recovery, preserve **all** current
database files, including `-wal`, `-shm` or `-journal` companions if present.

Run the following on the host, substituting actual IDs, dates and paths. Keep
configuration and all copies private. A restore rehearsal uses separate storage
and cannot replace the live database while any writer is active.

1. Create the marker and replace only this site’s `.htaccess` with `Require all
   denied`, after privately saving its original. Pause just the FutureMa cron
   entries using `crontab -e`, preserving everything else. Wait for active web
   requests to finish; Apache 403 prevents new requests. The marker blocks any
   request that reaches PHP, returning 503 for POST. Wait at least the host’s
   maximum PHP request lifetime or verify no active site requests with host
   process diagnostics. Check the sender lock before proceeding:

   ```sh
   cd /home/ACCOUNT/futurema
   touch var/maintenance
   cp /var/www/virtual/ACCOUNT/SITE/.htaccess var/htaccess.before-maintenance
   printf 'Require all denied\n' > /var/www/virtual/ACCOUNT/SITE/.htaccess
   flock -n var/letters.sqlite.lock /usr/bin/true
   ```

   A nonzero `flock` means wait for the sender. The lock does not drain active
   web requests; both checks are necessary. Confirm a POST cannot save.

2. Preserve the current stopped files, then stage a backup in private storage:

   ```sh
   mkdir -p var/recovery/current
   cp -p var/letters.sqlite* var/recovery/current/
   cp var/backups/futurema-YYYY-MM-DD.sqlite var/recovery/restored.sqlite
   cp config.php var/recovery/config.php
   chmod 600 var/recovery/config.php var/recovery/restored.sqlite
   ```

   Edit **only** the recovery config’s database to the absolute restored path,
   retain the original lock path, and set its maintenance path to the original
   marker. `status` opens the database but does not dump writing. Verify contents
   privately if needed; don’t paste personal text into logs or this repository.

3. Hold every uncertain letter before reconciliation:

   ```sh
   export FUTUREMA_CONFIG=/home/ACCOUNT/futurema/var/recovery/config.php
   /usr/bin/env -u PHPRC /usr/bin/php manage.php hold-pending
   /usr/bin/env -u PHPRC /usr/bin/php manage.php reconcile /home/ACCOUNT/futurema/var/recovery/current/letters.sqlite
   /usr/bin/env -u PHPRC /usr/bin/php manage.php status
   ```

   The readable current database restores known submission records for matching
   IDs. Review other letters: leave uncertain ones held; `retry ID` explicitly
   enables each verified pending letter. Run `PRAGMA integrity_check` on the
   restored database with `sqlite3` and require `ok` before installation.

4. If the current database cannot be read, leave every unsent restored letter
   held. In Gmail, open each received letter → More → **Show original**. In
   other clients use View source/original. Match the exact 32-character letter
   ID in `Message-ID: <ID@visible-sender-domain>` and its delivery date. Include
   mail delivered to old recipients; absence of a message proves nothing.

   ```sh
   /usr/bin/env -u PHPRC /usr/bin/php manage.php mark-submitted EXACT_ID UTC_UNIX_TIMESTAMP
   ```

   This updates only that matching pending ID. Use the known receipt/submission
   instant as a positive Unix timestamp. Mail history can be incomplete: keep
   uncertain letters held until you explicitly choose whether to risk a resend.

5. With all writers still stopped, stage the reconciled snapshot and install
   it on the same filesystem. Preserve old companions away from the live path:

   ```sh
   sqlite3 var/recovery/restored.sqlite 'PRAGMA integrity_check;'
   cp var/recovery/restored.sqlite var/letters.next.sqlite
   chmod 600 var/letters.next.sqlite
   mkdir -p var/recovery/replaced
   mv var/letters.sqlite* var/recovery/replaced/
   mv var/letters.next.sqlite var/letters.sqlite
   unset FUTUREMA_CONFIG
   /usr/bin/env -u PHPRC /usr/bin/php manage.php status
   sqlite3 var/letters.sqlite 'PRAGMA integrity_check;'
   ```

   Require correct records and `ok` before reopening. If verification fails,
   keep writers stopped and restore the preserved original set together.

6. Restore the saved site `.htaccess`, remove the marker, and reinstate the
   exact paused cron entries. The `rm` below removes only the maintenance marker:

   ```sh
   cp var/htaccess.before-maintenance /var/www/virtual/ACCOUNT/SITE/.htaccess
   rm var/maintenance
   /usr/bin/env -u PHPRC /usr/bin/php send-due.php
   ```

   Check no reconciled letter is resent, new web submissions work, and the next
   actual cron run succeeds. Rehearse both readable-current-DB and headers-only
   recovery, including incomplete history, before relying on the service.

## Updates, credentials, deletion and removal

Back up first, enable maintenance, and block/wait for writers as above for any
database replacement or migration. Preserve `config.php`, `var/`, the password
file and published `.htaccess`. Pull reviewed code, run checks and syntax lint,
publish updated public files, then reapply actual private paths. Do not copy the
example `.htaccess` over a working one without preserving its account values.
This first version creates its single table/index if missing and has no schema
migrations; future schema changes need an explicit migration and rollback guide.
Rollback code with writers stopped; do not restore an old database without
submission reconciliation. Never `git clean -fdx` in a deployment.

Rotate Basic Auth with `htpasswd -B /var/www/virtual/ACCOUNT/futurema-auth/.htpasswd
owner` (no `-c`). Rotate SMTP credentials in its private file only. Recipient
changes in `config.php` apply to every unsent letter; keep prior mailbox history
for recovery. Recheck a harmless test letter, quota, cron, backups and restore
after host/runtime migrations and at least quarterly. Periodically inspect Spam
and DKIM/SPF; the original host-default sender test had unreliable Gmail inbox
placement and did not establish sender DKIM signing.

To deliberately resend an already submitted letter, stop writers and preserve a
backup, then privately execute a prepared update for its exact ID setting
`submitted_at=NULL, attempts=0, last_error=NULL`. This is an explicit duplicate
delivery decision. To delete writing, stop writers, back up if desired, and
delete only its exact row. SQLite pages and existing backups can retain deleted
text; file deletion alone is not secure erasure. Retention changes must account
for off-host copies too.

To remove the service, block this site, remove just its cron entries, wait for
writers, and retain private code/config/database/backups until you explicitly
choose deletion. No account-wide mail or DNS changes are necessary.
