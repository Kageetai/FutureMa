# Manual backups and recovery

Backups are manual. From your private project directory on the server:

```sh
mkdir -p var/backups
chmod 700 var/backups
php manage.php backup "$PWD/var/backups"
```

The command prints a checked SQLite snapshot path. Keep a private off-host copy
using SFTP or rsync; snapshots contain readable letters. The server retains 30
dated snapshots, replacing the current UTC date's snapshot on repeat runs.
Off-host copies are not pruned. No manual run means no new backup.

## Restore

You are the only user, so there is no need to change web-server access or make a
separate recovery configuration. These steps use the default `var/letters.sqlite`,
lock and maintenance paths; adjust them if your configuration differs.

1. Finish or cancel any form submission, close the site, and comment out the
   FutureMa sender entry using `crontab -e`. Enable maintenance and wait for any
   running sender to finish:

   ```sh
   touch var/maintenance
   flock var/letters.sqlite.lock /usr/bin/true
   ```

2. Choose a snapshot from `var/backups/`. Replace `CHOSEN-SNAPSHOT.sqlite` below
   with its filename. Continue only after copying succeeds and integrity is `ok`:

   ```sh
   cp var/backups/CHOSEN-SNAPSHOT.sqlite var/restored.sqlite
   sqlite3 var/restored.sqlite 'PRAGMA integrity_check;'
   ```

3. Preserve the current database and any journal files, then install the snapshot.
   Keep this shell open for the next step:

   ```sh
   saved="$PWD/var/before-restore-$(date +%Y%m%d-%H%M%S)"
   mkdir -m 700 "$saved"
   for file in var/letters.sqlite var/letters.sqlite-wal var/letters.sqlite-shm var/letters.sqlite-journal; do
       if [ -e "$file" ]; then mv "$file" "$saved/"; fi
   done
   mv var/restored.sqlite var/letters.sqlite
   chmod 600 var/letters.sqlite
   ```

4. Recover recorded delivery history to avoid resending submitted letters:

   ```sh
   php manage.php reconcile "$saved/letters.sqlite"
   php manage.php status
   ```

   Check the result before resuming. If reconciliation fails, use the section
   below. An older snapshot can omit newer letters; keep the saved database
   until you have checked for these.

5. Remove the marker, uncomment the sender entry, and reopen the site:

   ```sh
   rm var/maintenance
   php send-due.php
   ```

## If the old database is unreadable

Keep maintenance enabled and cron paused. Run `php manage.php hold-pending`,
then `php manage.php status`. Check your inbox and enable only letters you want
to send with `php manage.php retry EXACT_ID`. Leave uncertain letters held:
missing mail is not proof of non-delivery, and retrying can create duplicates.
Resume with step 5 when satisfied.

To undo a failed restore, keep maintenance on and cron paused, move the restored
database and any journal files aside, and put the preserved original files back.
