# Manual backups and recovery

Backups are intentionally **manual**. No backup cron job or local scheduler is
installed. Run a snapshot whenever you want to preserve new letters, and before
updates, deletion or recovery. Examples assume the private project is
`/srv/futurema`; substitute your own paths, account and SSH host.

## Create and copy a snapshot

On the server, from the project directory:

```sh
php manage.php backup /srv/futurema/var/backups
```

The destination directory must already exist and be private. This uses SQLite's
backup API, checks integrity and atomically installs `futurema-YYYY-MM-DD.sqlite`.
It retains the 30 most recent dated snapshots; repeat runs on one date replace
that date's snapshot. **No manual run means no new snapshot.** Never copy an
actively written database as a backup.

From your own computer, copy snapshots to private off-host storage:

```sh
mkdir -p /path/to/private/futurema-backups
chmod 700 /path/to/private/futurema-backups
rsync -a USER@HOST:/srv/futurema/var/backups/ /path/to/private/futurema-backups/
sqlite3 /path/to/private/futurema-backups/futurema-YYYY-MM-DD.sqlite 'PRAGMA integrity_check;'
```

Expect `ok`. The off-host copies contain readable letters: protect the disk and
manage their retention manually. Server retention does not prune off-host files.

## Restore

Keep both web submissions and the sender stopped throughout replacement.
Restoring old submission state without reconciliation can resend letters.

1. Create the configured maintenance marker (`var/maintenance` by default),
   block this site's HTTP requests at the web server, and pause only its sender
   cron entry. Wait for active web requests and the sender to finish. For the
   default lock, `flock -n var/letters.sqlite.lock /usr/bin/true` must succeed;
   this does not itself drain web requests. Preserve the original server/cron
   settings and verify POST cannot save.
2. Preserve the stopped database **and any `-wal`, `-shm`, or `-journal` companion
   files**. Copy the chosen backup to private temporary storage. Keep the
   original live files until recovery has been verified.
3. Make a private recovery config pointing at the temporary restored database.
   Explicitly keep `lock` and `maintenance` pointing at the original live paths.
   Select it before modifying the restored state:

   ```sh
   export FUTUREMA_CONFIG=/srv/futurema/var/recovery/config.php
   php manage.php hold-pending
   php manage.php reconcile /srv/futurema/var/recovery/current/letters.sqlite
   php manage.php status
   ```

   `hold-pending` excludes every unsent restored letter from sending. `reconcile`
   copies known submission records for matching IDs from the preserved current
   database. Enable each verified pending letter with `php manage.php retry ID`.
   Leave uncertain letters held.
4. If the current database is unreadable, inspect received messages' original
   headers, including mail sent to previous recipients. Match the exact letter
   ID in `Message-ID: <ID@sender-domain>` and record its known receipt/submission
   time as a positive UTC Unix timestamp:

   ```sh
   php manage.php mark-submitted EXACT_ID UTC_UNIX_TIMESTAMP
   ```

   This updates only the matching pending ID. Missing mail is not evidence that
   a letter was never submitted. Keep uncertain letters held until explicitly
   deciding whether to risk resending; incomplete mailbox history cannot
   guarantee duplicate-free recovery.
5. Run `sqlite3 RESTORED_PATH 'PRAGMA integrity_check;'` and require `ok`. With
   all writers still stopped, move the current database and its companions to
   preserved storage. Install the reconciled database at the configured live
   path with private permissions; do not leave old journal files alongside it.
   Unset `FUTUREMA_CONFIG`, inspect `php manage.php status`, and check the live
   database's integrity before reopening. If verification fails, keep writers
   stopped and restore the preserved original set together.
6. Restore server access, remove only the maintenance marker, and reinstate the
   sender cron entry. Run `php send-due.php`; confirm reconciled letters are not
   resent, new submissions work, and the next scheduled run succeeds.

Rehearse this on isolated data, including a backup predating a successful
submission, readable-current-database recovery, headers-only recovery, and
incomplete mailbox history. Code rollback alone does not require restoring an
older database; if replacing one, use the same procedure.
