# Delivery and maintenance

From the private project directory:

```sh
php manage.php status
```

This shows IDs, UTC timestamps, zones and safe failure state without printing
letter contents. `submitted_at` means accepted by the transport, not received
in the inbox. Monitor the envelope sender's mailbox for bounces and check Spam.
A later bounce does not automatically requeue a letter.

## Failed delivery

A failed handoff stops the remaining queue until the next hourly run. Other
than a recognized rate-limit response, each failure consumes one of five
attempts. Exhausted letters stay stored and are skipped so later letters can
proceed. Recognized rate limits never consume attempts.

Rate-limit classification is specific to the installed transport: the current
sendmail implementation recognizes exit 1 with stderr starting
`You are sending too many mails.`. Other responses, including timeouts and
unclassified failures, use the bounded retry policy. Verify your provider's
signals before relying on rate-limit handling. Raw transport output is not
persisted because it may contain personal text or credentials.

Check private `var/sender.log`, cron output, executable paths, permissions,
PHP configuration, disk quota and mail routing. After fixing the cause, create
the configured maintenance marker and wait for an active sender to finish:

```sh
touch var/maintenance
php manage.php retry EXACT_ID
rm var/maintenance
```

Use your configured marker path if different. `retry` enables only a matching
unsent letter. Retrying an uncertain handoff may cause a duplicate.

## Credentials and data

Rotate Basic Authentication using your server's password tool; for Apache,
`htpasswd -B /private/path/password-file owner` updates an existing file without
recreating it. Keep SMTP credentials private and rotate them at the provider too.

To deliberately resend a submitted letter or delete writing, first stop writers
and [back up](BACKUPS.md). Use a prepared SQL statement for the exact letter ID.
Resending resets `submitted_at=NULL, attempts=0, last_error=NULL`; deletion removes
that row. SQLite pages and backups may retain deleted text. Do not treat ordinary
file or row deletion as secure erasure.

After host/runtime changes, and periodically, verify a harmless scheduled letter,
mail authentication and inbox placement, storage quota, manual backups and restore.
