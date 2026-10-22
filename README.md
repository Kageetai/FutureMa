# FutureMa

A single-user app to write a private letter, choose a future date, hour and time
zone, and receive it by email. Built with PHP, SQLite and an hourly cron job—no
framework, JavaScript or build step.

## How it works

The password-protected form saves your letter with a fixed delivery instant.
The sender submits it on the first hourly run at or after that time, catching
up after downtime. Hours skipped or repeated by daylight-saving changes are
rejected. Zones with fractional-hour offsets may wait until the next cron run.

One recipient is configured privately. There is no public registration, letter
archive or reading endpoint. Submitted letters are retained for recovery.

## Requirements

- A Unix-like web host with HTTPS, PHP 8.4+, and scheduled CLI jobs.
- PDO SQLite, SQLite3, mbstring and sessions; CLI POSIX/PCNTL, `proc_open` and `flock`.
- Local sendmail, or an explicitly configured msmtp transport.
- Web-server authentication. An Apache `.htaccess` template is included; other
  servers need equivalent configuration.

## Get started

Clone the repository, copy `config.example.php` to private `config.php`, and set
your database path, recipient and envelope sender. Initialize the database:

```sh
php manage.php init
php tests/check.php
```

Then publish only `public/`, configure HTTPS and authentication, and schedule
`send-due.php` hourly. Follow the [deployment guide](docs/DEPLOYMENT.md) for setup
and verification.

## Guides

- [Deployment and updates](docs/DEPLOYMENT.md)
- [Configuration and optional SMTP](docs/CONFIGURATION.md)
- [Manual backups and recovery](docs/BACKUPS.md)
- [Delivery failures and maintenance](docs/OPERATIONS.md)

Letters and backups are readable to the hosting account, not encrypted. Mail
handoff does not guarantee inbox arrival. A crash immediately after acceptance
can cause a duplicate on retry. Monitor the bounce mailbox and keep backups.
