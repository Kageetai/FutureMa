# Implementation and release acceptance

## Acceptance — 22 October 2026

The owner accepted the manual test plan as complete and approved release.
The manual plan covered authentication, phone/keyboard use, validation, saving,
ordinary duplicate protection, scheduled email delivery, private-file protection
and backups. The owner confirmed a successful manual backup and accepted the
simplified single-user restore instructions.

## Automated and deployment verification

- 152 automated checks pass, including HTTP form requests, Unicode limits,
  persistence, CSRF, date/time-zone validation, hourly scheduling, transport
  deadlines, retry handling, backups and recovery.
- All PHP source syntax checks passed. Documentation links and shell examples
  were checked.
- The deployed application accepted full-size Unicode letters, rejected invalid
  requests, protected private files and delivered a cron-triggered smoke letter
  to the owner's inbox.
- An earlier isolated restore rehearsal checked database integrity, reconciliation
  of recorded submissions and holding uncertain letters. Maintenance blocked
  submissions during that rehearsal.
- Independent review findings were addressed before acceptance: transport process
  groups are terminated on timeout, the browser no longer truncates valid Unicode
  subjects, and isolated smoke configuration instructions were clarified.

## Operating scope

One owner, one configured recipient, plain-text letters and hourly delivery.
Backups remain manual. Private configuration, credentials, writing, databases,
backups and logs are excluded from source control.

Mail handoff does not guarantee inbox arrival. A crash immediately after handoff
can cause a duplicate on retry; restoring old delivery state requires reviewing
submission history. See [operations](OPERATIONS.md) and [backups](BACKUPS.md).

The original [implementation plan](../IMPLEMENTATION_PLAN.md) is historical
research; its original checkboxes are not the current acceptance record.
