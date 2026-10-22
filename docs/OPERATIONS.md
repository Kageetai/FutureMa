# Delivery and maintenance

From the private project directory, inspect delivery state with:

```sh
php manage.php status
```

This shows letter IDs, UTC timestamps and errors without printing your writing.
`submitted_at` means the mail transport accepted the letter; check your inbox,
Spam and the envelope sender's bounce mailbox for actual delivery.

## Retry a failed letter

Failures defer the remaining queue until the next hourly run. After five
failures, a letter is kept but skipped until you retry it. Recognized rate limits
do not consume attempts. Check `var/sender.log`, cron output, disk space and mail
configuration before retrying.

Stop using the form while maintenance is enabled. The retry command refuses to
run while another sender holds the lock; wait and try again if necessary:

```sh
touch var/maintenance
php manage.php retry EXACT_ID
rm var/maintenance
```

Remove the marker only after the retry command succeeds. Use your configured
marker path if different. Only a matching unsent letter is enabled for retry;
a later bounce does not automatically requeue anything. Retrying an uncertain
handoff can cause a duplicate.

## Updates and credentials

[Back up manually](BACKUPS.md) before updates. Ordinary code updates do not need
a database restore. For database replacement, follow the [single-user restore
steps](BACKUPS.md#restore): stop using the form, pause cron and enable maintenance.

Rotate your password with the web server's password tool. For Apache,
`htpasswd -B /private/path/password-file owner` updates an existing file without
recreating it. Keep SMTP credentials private and rotate them at the provider too.

To deliberately resend a submitted letter or delete writing, first back up and
pause activity as for a restore. Use a prepared SQL statement for the exact ID.
Resending resets `submitted_at=NULL, attempts=0, last_error=NULL`; deletion removes
that row. Deleted text may remain in SQLite pages and backups.
