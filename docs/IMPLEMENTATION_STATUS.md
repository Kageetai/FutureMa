# Implementation record

Plan: `IMPLEMENTATION_PLAN.md` (also the design/specification).

The owner authorized implementation and requested this checkout. Work is on
`codex/implement-futurema`. No application or hosting changes existed at baseline.
The historical hosting checks in the plan are evidence from 17 October.
The new implementation was deployed and checked on 19 October as recorded below.

Pre-flight interfaces: the form saves UTC timestamps plus the selected IANA zone;
the sender consumes those timestamps and current private mail configuration;
recovery must reconcile submitted IDs before the sender resumes. No conflicting
interfaces found.

Decisions:
- Use the existing plan as the approved design and implement directly; it is a
  requirements checklist rather than generated per-task briefs.
- Resolve the default visible sender from the OS account and host, independently
  of the envelope sender; require deployment verification of that identity.
- Use a private maintenance marker checked by both form and sender. Database
  replacement still requires blocking HTTP access and waiting for active writers.
- Per the owner's 19 October instruction, backups are manual. No backup cron job
  or Mac scheduler remains installed. README contains exact manual snapshot and
  off-host copy commands; off-host copying is left to the owner.

Progress:
- Baseline: only the implementation plan; no application tests to run.
- Persistence/validation, form, sender, manual backup/recovery tools: implemented.
- PHP 8.4.26 local suite: 152 checks passed; all PHP syntax checks passed.
- Independent review: three actionable findings addressed: process-group timeout
  termination (regression reproduced then passed), browser UTF-16 subject limit
  removed, and isolated smoke configuration commands made explicit. No deferred
  minor findings. The timeout helper adds CLI POSIX/PCNTL requirements, verified
  on the host. Examples remain credential-free.
- Host suite: final 152 checks passed on Uberspace 7 / PHP 8.4.26, including
  first-eligible-hour checks for all specified zones. All PHP syntax checks passed.
- Deployed at https://futurema.kageetai.net/; SSH account `kagee7ai@kageetai.net`,
  host `neujmin.uberspace.de`, private code `/home/kagee7ai/futurema`.
- Live HTTPS: anonymous GET/POST 401, authenticated form 200, config/database
  URLs 404, `.user.ini` 403. Full 100,000-character four-byte Unicode body and
  200-character four-byte subject persisted in isolated storage. Duplicate POST
  did not duplicate the row; 100,001-character body rejected.
- Temporary smoke cron handed off one harmless letter at 10:24:02 UTC
  (12:24:02 Europe/Berlin). Owner confirmed inbox receipt. The temporary entry
  was removed. Permanent sender is `0 * * * *`; original crontab settings were
  preserved, with `PHPRC` unset only for this application's command. Host cron
  time zone reported CEST +0200 at deployment. The next permanent hourly run has
  not yet been observed; the actual cron smoke invocation exercised its command.
- Application maintenance rejected a live POST with 503. The isolated smoke DB
  was restored while HTTP was denied and the sender cron paused; both lock paths
  were free and integrity checked before resuming. The normal DB remained empty.
- Recovery rehearsal covered a snapshot predating submission, current-DB
  reconciliation, and exact-ID marking with incomplete history held at five
  attempts. The exact-ID rehearsal used the emitted ID/submission timestamp;
  independently inspecting received-message original headers remains an owner
  check. New Return-Path/SPF/DKIM header inspection was not claimed.
- Private login details are in ignored local `var/access.txt` (0600), never in
  tracked source. Site password file is Apache-readable outside the document
  root; configuration/storage remain private in the account's home directory.
- Manual consistent snapshot created on the host. Off-host copying and real
  phone/keyboard use are not yet exercised. Restore depends on retained mailbox
  history if the current database is unreadable; incomplete history cannot prove
  whether a letter was submitted.
- Source stays on `codex/implement-futurema`; it has not been pushed/merged.
  A fresh GitHub-clone deployment rehearsal is pending publication of the code.
- Clean export of staged source, without private configuration/storage: the
  documented `php tests/check.php` command passed all 152 checks.
