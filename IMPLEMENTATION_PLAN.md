# Personal FutureMe: research and implementation plan

Researched 17 October 2026. Status: proposed plan; no application or hosting changes made.

## Goal and proposed scope

Write a private letter in a browser, configure its future delivery date and time, and receive the letter by email on or after that scheduled time. Keep this small enough to understand and maintain yourself on an existing Uberspace account.

The reference website is [FutureMe.org](https://www.futureme.org/). The mail configuration files considered in this research are `.qmail` files.

Proposed minimal scope: one person, one configured recipient, a configurable delivery date, time, and time zone for each letter, and no attachments or public submissions.

The future GitHub repository must include documentation sufficient to deploy and operate the project on Uberspace from a fresh checkout, without this chat or undocumented manual steps.

## Research findings

- FutureMe's core interaction is writing a letter, selecting a date, and supplying an email address. Its public letters, accounts, and media features are unnecessary here. [FutureMe](https://www.futureme.org/?redirect=1)
- Uberspace 7 runs PHP through its managed PHP-FPM service. PHP files can be published directly in the document root; SQLite, PDO SQLite, sessions, and mbstring are available. This avoids managing a separate application process. The manual currently lists PHP 8.5 as its default; verify the account's actual supported version before deployment. [PHP](https://manual.uberspace.de/lang-php/)
- Cron supports recurring jobs, including hourly at minute zero (`0 * * * *`). One recurring sender can process all due letters; no individual cron entry is needed for each letter. [Cron](https://manual.uberspace.de/daemons-cron/)
- Uberspace manages HTTPS and certificate renewal for ordinary PHP sites. [HTTPS](https://manual.uberspace.de/web-https/)
- `.qmail` files control incoming mail routing and forwarding, not future scheduling. Uberspace documents deprecated qmail configurations as unavailable on the upcoming U8 mail system. Avoid building a new service around them. [Home directory](https://manual.uberspace.de/basics-home/), [Qmail migration](https://manual.uberspace.de/migration/qmail/)
- Uberspace supports local mail submission through sendmail and provides `msmtp` as an alternative for authenticated SMTP submission. Its current U7 changelog documents an enforced 60-mails-per-hour limit; verify applicable limits on the actual account and keep the sender comfortably below them. [Changelog](https://manual.uberspace.de/changelog_archive/)
- Custom mail domains need appropriate SPF/DKIM configuration. Use an existing working sender or the account's default mail domain first; do not move an existing domain's MX records just to build this app. [Mail domains](https://manual.uberspace.de/mail-domains/), [Default-domain DKIM update](https://manual.uberspace.de/)

These findings establish feasibility on documented Uberspace 7 facilities. They do not establish the version, configuration, or mail deliverability of your account. Recheck against its installed generation, especially if it is U8.

## Options

| Approach | Benefits | Costs | Recommendation |
| --- | --- | --- | --- |
| Private text/JSON files + scheduled script | Smallest if you are happy creating files over SSH/SFTP; no website needed | Requires careful atomic writes, locking, and send-state bookkeeping; less convenient on a phone | Choose only if a browser form is unnecessary |
| PHP form + SQLite + cron | Browser interface, one database file, managed web runtime, no framework/build step | A little PHP and a few operational checks | Recommended for the requested website |
| Python/Node web app + SQLite + cron | Familiar if you strongly prefer either language | Separate web process, supervisor configuration, and usually dependencies | Feasible, but more moving parts for this scope |

SQLite is still a file on disk: it needs no database server. It supplies transactions and safe concurrent writes instead of making us implement a miniature database around JSON files. Storing `.eml` files alone also does not schedule delivery.

## Proposed behavior

1. Open a private page protected by HTTP Basic Authentication over HTTPS.
2. Enter a subject, plain-text letter, and delivery date using a native date input. Select a whole delivery hour from a dropdown (`00:00` through `23:00`); minutes and seconds are always zero. Select the delivery time zone from a labeled dropdown of IANA time zones (for example, Europe/Berlin, America/New_York, or Asia/Tokyo). Default to Europe/Berlin and allow a different selection for every letter; no browser detection or JavaScript is required.
3. Submit and see a confirmation with the selected date, time, and time zone; success appears only after the database commit.
4. The scheduled job runs hourly at minute zero in the host's cron time zone and submits the letter on the first run at or after the selected delivery time. Inbox arrival also depends on mail processing. A selected zone with a half-hour or quarter-hour offset may place its whole hour between cron runs; submission then waits until the next run, less than one hour later under normal operation. State this hourly scheduling behavior beside the form.

Interpret the selected local date and time in the selected zone using its rules for the delivery date, including daylight saving. Scheduling fixes the delivery instant; later travel or a different zone selected for another letter does not change an existing letter's schedule.

The recipient and sender live in private configuration, not in the form. This avoids recipient verification and prevents the website from becoming a general-purpose mail sender.

Do not expose a letter archive or letter-reading endpoint in v1. The owner can still inspect the database: this provides the experience of putting a letter away, not a cryptographic time lock. Messages and their backups are readable by anyone who gains access to the hosting account.

## Architecture and files

Flow: password-protected browser form → PHP validation → SQLite → cron sender → Uberspace’s default local sendmail transport → your inbox. Optionally use msmtp with a private SMTP configuration instead.

Proposed repository files:

- `public/index.php`: GET form and POST submission, CSRF token, validation, confirmation.
- `public/.htaccess`: Basic Authentication for the entire public directory and directory listing disabled. Password file resides outside the web root.
- `app.php`: configuration loading, SQLite initialization, shared validation and message construction. Keep ordinary functions; no service/container architecture.
- `send-due.php`: command-line-only sender, process lock, due selection, mail submission, status recording.
- `config.example.php`: example paths, configured sender and recipient; no real credentials.
- `tests/check.php`: small executable test script using temporary storage and a fake mail transport.
- `README.md`: project overview, requirements, complete Uberspace deployment guide, configuration reference, usage, verification, updates, backup, recovery, and troubleshooting. This is the entry point on GitHub.
- `.gitignore`: private configuration, credentials, database files, and generated artifacts.

Deploy only the contents of `public/` into the dedicated site document root. Place application code, private configuration, SQLite, logs, and any optional SMTP credentials outside it. Use restrictive filesystem permissions and verify that both web PHP and command-line PHP can access the required private paths under Uberspace's PHP restrictions.

Use PHP 8.4 or newer if available on the account, built-in PDO SQLite and sessions, ordinary HTML/CSS, and Uberspace's default local sendmail transport. msmtp is needed only for the optional custom SMTP path. No Composer or npm dependencies, JavaScript requirement, background web daemon, or database service.

One SQLite table, `letters`:

- `id`: random identifier, also used for a stable email Message-ID.
- `created_at`, `due_at`: UTC timestamps; convert the selected delivery date and time from the selected IANA time zone before saving. There is no fixed delivery hour.
- `delivery_timezone`: the selected IANA identifier, retained for displaying the scheduled time accurately.
- `subject`, `body`: plain text.
- `submission_key`: unique random form identifier, preventing duplicate records from resubmitting the same form.
- `submitted_at`: null until the selected mail transport accepts the message; this means accepted for delivery, not proven inbox arrival.
- `attempts`, `last_error`: count failures toward the retry allowance and retain bounded diagnostic information. Rate-limit responses update `last_error` without incrementing `attempts`. Never log letter bodies or credentials.

One private config contains paths and recipient, with optional sender and transport overrides. By default, use Uberspace’s existing local sendmail configuration and the account’s default mail identity; no SMTP host, port, username, password, or custom DNS setup is required in the application. Verify the actual default identity during the hosting check. Use the current configured recipient at send time so an address change also covers unsent letters. For custom mail settings, allow an optional sender override and an explicit msmtp transport selection using a private msmtp configuration file. Keep SMTP host, port, authentication, and TLS settings in that file rather than duplicating them in application configuration. Do not alter the account-wide mail configuration or silently fall back to another transport after an error.

## Delivery and failure rules

- Run the sender hourly at minute zero (`0 * * * *`). Select unsent records with `due_at <= now`, oldest first. Missed jobs therefore catch up automatically after downtime.
- Acquire a nonblocking process lock before selecting anything; an overlapping invocation exits without sending.
- Process eligible due letters oldest first with no fixed five-letter cap. On a temporary transport failure, including a rate-limit response, stop the run and leave the failed letter and all remaining letters pending for the next hourly cron run. Previously accepted letters remain marked submitted and are not retried.
- Rate-limit responses never consume a letter's retry allowance, regardless of how many hourly runs encounter the limit. Other failed submissions count toward a limit of five failures per letter before manual intervention; retain the letter and report the exhausted allowance. Skip exhausted letters on subsequent runs so they do not block the queue. A failure specific to one letter must not discard other letters.
- Inspect the transport's exit status and bounded error output to classify failures. Verify the actual Uberspace default sendmail rate-limit and temporary-failure signals during deployment; do not assume SMTP status codes are exposed by the local interface. An unclassified failure stops the run, preserves pending letters, and follows the bounded non-rate-limit retry policy rather than being silently treated as success or an unlimited rate-limit retry.
- Send through Uberspace’s local sendmail interface by default. Resolve its executable path during deployment. Invoke it with a fixed argument list and message input on stdin, never a shell command containing form text; enforce an explicit process timeout. Optional custom SMTP uses msmtp in the same submission path with its private configuration, authentication, TLS certificate verification, and timeout. A failed default submission is an error, not a reason to require custom SMTP.
- Build a valid UTF-8 plain-text MIME message using built-in encoding facilities. Validate the resolved default sender or explicit sender override and the configured recipient; reject newlines in the subject. Generate a stable Message-ID from the letter ID and resolved sender domain.
- Mark `submitted_at` only after a successful transport exit. Record failures, emit an error on stderr, and exit nonzero if any submission fails. Cron output must reach a monitored mailbox or log.
- Local mail handoff or SMTP acceptance does not guarantee inbox arrival. Configure a working return address and monitor bounces; a later bounce does not automatically reset a record for resending.
- A crash after the mail transport accepts a message but before the database update can cause a duplicate on retry. Accept this small risk rather than risk silently losing a letter. Stable Message-ID helps investigation but does not guarantee deduplication.
- Retain submitted letters in v1 for recovery; do not silently delete personal writing. Document manual deletion and its effect on backups.
- Before replacing a database during restore or rollback, disable web submissions, pause cron, and wait for active web requests and any running sender to finish. Preserve the current database and its SQLite companion files, if present, before replacement. Keep submissions and the sender disabled until the restored database has been reconciled and verified.
- Reconcile submission history in the restored database before enabling cron. If the current database is readable, use its submission records; otherwise manually match received emails to restored letters by the letter ID in their Message-ID headers, including messages sent to previous configured recipients. Document how to inspect those headers and update only the matching records. Missing mail is not proof that a letter was never submitted: leave uncertain letters excluded from sending using the existing exhausted-attempts mechanism until the owner chooses whether to resend. This manual recovery depends on retained mailbox history and cannot guarantee duplicate-free recovery when that history is incomplete.

## Implementation sequence

### 1. Verify hosting and one real email

- [ ] Confirm whether the account is U7 or U8, its selected PHP version, PDO SQLite/session availability, usable private paths, cron, and the default local sendmail executable. Check msmtp only if optional custom SMTP is selected.
- [ ] Choose a dedicated HTTPS site address and recipient; identify the account’s default sender without requiring an override. Confirm Basic Authentication works on that site without affecting other sites.
- [ ] Submit a harmless test email through Uberspace’s existing default mail configuration without custom SMTP credentials; check receipt, sender identity, spam placement, SPF/DKIM results, and bounce address. Verify rate-limit and temporary-failure exit statuses/messages from host documentation, the installed wrapper, or provider guidance without deliberately exhausting the account's mail quota; record the verified classification and test it with fake transport responses. Document custom SMTP via msmtp as optional setup only; do not require it for the default deployment.
- [ ] Record the host-specific paths and commands in README. Do not build further around a mail path that has not worked end to end.

Deliverable: documented hosting configuration and one received test email.

### 2. Save a letter safely

- [ ] First create checks for SQLite persistence, duplicate submission keys, exact valid date/hour parsing, rejection of nonzero minutes or seconds, past times, a future whole hour later today, different user-selected delivery hours and zones, invalid zone identifiers, empty text, Unicode, subject header injection, and length limits.
- [ ] Implement the table and shared save logic using prepared SQL. Use subject length 1–200 characters, body length 1–100,000 characters, and a total encoded request limit of 2 MiB to accommodate URL-encoded UTF-8 text and the other form fields. Align PHP's `post_max_size` and any web-server request limit so they permit the application's full 2 MiB limit; reject oversized requests clearly without showing success. Populate and validate time zone choices using PHP's built-in IANA time zone list. Require a valid delivery date and whole hour strictly in the future in the selected zone; reject submitted values with nonzero minutes or seconds on the server as well as restricting the form. Reject nonexistent or ambiguous local times at daylight-saving transitions with a clear message asking for another time; do not silently adjust them.
- [ ] Build the password-protected accessible form with explicit labels, useful validation messages, HTML escaping, session CSRF protection, and POST/redirect/GET. Preserve entered text when validation fails. Set secure, HttpOnly, SameSite session cookies and `Cache-Control: no-store`.
- [ ] Test missing/invalid CSRF tokens, unauthenticated GET and POST, database write failure, double submission, and keyboard/mobile use. Submit a complete URL-encoded form over HTTP with a 100,000-character body of four-byte Unicode characters and verify persistence; also verify rejection of 100,001 characters and requests over 2 MiB. A failed save must never show success.
- [ ] Run `php tests/check.php` and syntax checks for all PHP files.

Deliverable: authenticated browser submissions survive a new PHP process and cannot be duplicated by reusing the same submission key.

### 3. Send due letters

- [ ] Add a fake transport to the checks before implementing delivery: a future letter is untouched; more than five due letters all submit when transport succeeds; a second run does not resubmit success. A temporary failure stops further submissions and retains the failed and remaining letters; the next hourly run retries them without resubmitting prior successes. Repeated rate-limit responses across more than five runs leave `attempts` unchanged and the letter eligible; a later successful run drains the pending queue. Other failures increment `attempts`, and exhausted letters are retained and skipped without blocking later letters. Verify unclassified failures stop the run and use the bounded retry policy.
- [ ] Test that absent transport overrides select local sendmail without SMTP credentials, an explicit msmtp configuration selects custom SMTP, and failure of either transport does not silently switch to the other. Implement the CLI sender with lock, timeout, failure classification, the retry allowance above, UTF-8 message construction, stable Message-ID, and the rules above. Guard against invocation through HTTP.
- [ ] Test overlapping invocations, transport timeout, failure on one letter without losing others, attempt exhaustion, UTC conversion in summer and winter across Europe/Berlin, America/New_York, and Asia/Tokyo, rejection of nonexistent/ambiguous daylight-saving times, and submission on the first hourly run at or after the configured due time but never before it. Include Asia/Kolkata and Asia/Kathmandu to verify catch-up at the next cron run for half-hour and quarter-hour offsets. Verify changing the server time zone or scheduling a subsequent letter in another zone does not move an existing letter's due time. Inject a clock into the shared due-date logic so tests do not wait for real dates.
- [ ] Run `php tests/check.php` and syntax checks. Verify generated MIME headers/body using a local capture transport without sending real letters.

Deliverable: repeatable tests demonstrate scheduling and safe failure handling.

### 4. Deploy and make it recoverable

- [ ] Deploy to the dedicated site; verify authentication, HTTPS, and that database/config/log/password paths cannot be downloaded.
- [ ] Install a cron entry with schedule `0 * * * *`, with absolute paths to PHP and the sender. Record the host's cron time zone, enable monitored error output, and document checking the last successful run.
- [ ] Create a letter through the real form, advance its due timestamp only in an isolated smoke-test database, and run the same sender command cron uses. Confirm one received message and no second submission on rerun. Then verify an actual scheduled cron invocation.
- [ ] Schedule daily SQLite-consistent backups using SQLite's backup facilities, with 30 daily copies and an off-host copy. Protect these like the live letters. Do not rely on copying an actively written database file.
- [ ] Rehearse recovery: disable web submissions, pause cron, wait for active requests and the sender to finish, and preserve the current database and any SQLite companion files. Restore a backup to temporary storage, verify its letters, reconcile submission history there, then replace the live database while all writers remain stopped. Verify the installed database before reopening submissions and resuming cron. Confirm POST requests cannot save letters during maintenance.
- [ ] Test recovery with a backup that predates a successful submission: reconcile from the readable current database, then repeat using only received-message headers. Confirm the reconciled letter is not resent. Test incomplete mailbox history: uncertain letters remain excluded from sending until an explicit owner decision, while verified pending letters can proceed.
- [ ] Document credential rotation, recipient changes, inspecting exhausted failures, deliberate resend, storage/quota checks, and a periodic test email. Review operation after host/runtime migrations and at least quarterly.

Deliverable: working personal website, scheduled mail, and a demonstrated restore procedure, using default Uberspace mail without custom SMTP setup. Optional custom SMTP has its own configuration and transport-selection checks.

### 5. Document and verify deployment from GitHub

- [ ] Write the README alongside implementation, keeping commands and example configuration aligned with the actual files. Explain the purpose, minimal feature set, architecture, runtime requirements, and privacy/delivery limitations.
- [ ] Provide an ordered Uberspace installation guide with copyable commands: clone the GitHub repository, check PHP/extensions and default mail, create private storage/configuration with appropriate permissions, initialize SQLite, publish only `public/`, configure password protection and HTTPS, run checks, install the hourly cron entry, and verify one scheduled delivery. Explain where every command runs and which account, domain, repository URL, recipient, and absolute-path values must be substituted. Preserve existing sites and cron entries.
- [ ] Document every supported configuration key, its default, whether it is required, and one minimal working example using Uberspace's default mail. Put optional sender/custom SMTP configuration in a separate optional section with a credential-free example. Never require optional SMTP setup to complete the installation guide.
- [ ] Include usage instructions for selecting a date, whole hour, and time zone; explain hourly submission, fractional-offset zones, daylight-saving validation, and the fixed delivery instant after scheduling.
- [ ] Include exact check commands and expected results, log locations, common permission/authentication/mail/cron failures, and steps to inspect and retry exhausted submissions. Explain how to distinguish mail handoff from inbox receipt, how temporary failures defer the remaining queue to the next hourly run, and why rate-limit responses never exhaust a letter's retry allowance.
- [ ] Document updating the application while preserving private configuration, letters, and backups; backing up before an update; any database migration steps; and the restore/rollback sequence above with both web submissions and the sender stopped during database replacement. Include exact maintenance, Message-ID inspection, reconciliation, and resume commands, including how to exclude uncertain letters and deliberately retry them. State the dependency on retained mailbox history when the current database is unavailable. Include recipient changes and removal instructions that preserve personal data unless deletion is explicitly chosen.
- [ ] Rehearse deployment from a fresh checkout into a separate test location using only the committed README and example files. Verify the default mail path, authentication, storage protection, hourly cron, scheduled delivery, and restore. Correct every missing or inaccurate step and record the tested Uberspace generation and PHP version in the README. Do not claim compatibility with untested hosting generations.
- [ ] Before the repository is published to GitHub, review the tracked files for private configuration, real credentials, letters, database files, backups, and logs. Keep examples sanitized and ensure the README uses the actual repository URL once it exists. Repository creation/publication is a later action, not part of this planning update.

Deliverable: GitHub-ready project documentation and a successful deployment rehearsal requiring no instructions from this chat.

## Definition of done

A letter can be submitted privately from a phone with a user-selected delivery date, whole hour, and time zone, survives restarting PHP, is not sent before that time, reaches the configured inbox through the scheduled sender, and is not resubmitted by an ordinary rerun. Downtime catches up, failed submissions preserve content, and a backup can be restored. The GitHub repository contains a complete README and sanitized example configuration. Deployment from a fresh checkout, verification, updates, and recovery have been rehearsed using the documentation alone, without relying on this chat.

## Deliberately omitted

Public registration, multiple users/recipients, inbound email ingestion, attachments, rich text, public letters, recurring reminders, editing/cancellation screens, frameworks, containers, Redis, custom SMTP implementation, and cryptographic time locking. Add none unless personal use demonstrates a need.

