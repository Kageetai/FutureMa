# Deployment

Use a Unix-like host that meets the [requirements](../README.md#requirements).
The commands below are examples: replace `/srv/futurema`, `/srv/www/futurema`,
`/srv/auth/futurema.htpasswd`, the PHP executable and domain with your host's
actual paths. Run commands as the application owner, with permission to create
those directories. PHP extensions and process functions must be available to
CLI PHP; web PHP needs SQLite, mbstring and sessions.

## Install privately

Keep code, configuration, SQLite, logs and backups outside all document roots.
Only the PHP application user and authorized administrators should access them;
adapt permissions or ACLs to your host's web/CLI users.

```sh
git clone https://github.com/Kageetai/FutureMa.git /srv/futurema
cd /srv/futurema
umask 077
mkdir -p var/backups
cp config.example.php config.php
chmod 700 var var/backups
chmod 600 config.php
```

Edit the absolute database path, recipient and envelope sender in `config.php`.
Verify the local sendmail executable and default visible sender, or configure
[msmtp explicitly](CONFIGURATION.md#optional-smtp).

```sh
php -v
php -m
php manage.php init
php tests/check.php
php manage.php status
```

Expect PHP 8.4+, a passing check count and `[]` for a fresh database. Tests use
fake mail transports and temporary storage; they need a local listening socket.
To syntax-check the source, run:

```sh
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

## Publish the form

Point a dedicated HTTPS site at a directory containing **only** `public/` files.
For hosts with a separate document root:

```sh
cp public/index.php public/.htaccess public/.user.ini /srv/www/futurema/
```

Create the document root first if necessary. In its `.htaccess`, replace the
entire `AuthUserFile` and `FUTUREMA_APP` paths with your private password file
and `/srv/futurema/app.php`. Create the password file outside all served roots:

```sh
htpasswd -cB /srv/auth/futurema.htpasswd owner
```

Use `-c` only when creating a new file. The web server must be able to read it;
other users should not have access. The PHP user must be able to load private
code/configuration and write the database directory and session storage.

For a server without `.htaccess`, configure equivalent authentication, directory
listing/dot-file protection and a 2 MiB request limit. Pass the authenticated
identity as `REMOTE_USER` and the private app path as `FUTUREMA_APP` to PHP.
`PHP_AUTH_USER` alone is insufficient. PHP must also receive the trusted HTTPS
state; configure TLS/proxy handling at the server, not from client headers.

The included `.user.ini` sets `post_max_size=3M` so PHP permits the application's
full 2 MiB encoded request limit. Verify your host honors this file or set the
same value in its PHP configuration. Keep errors and request bodies out of
public responses/logs. Configure DNS and a valid TLS certificate before using
Basic Authentication.

## Verify and schedule

Confirm anonymous GET and POST return 401, authenticated GET displays the form,
HTTP cannot accept submissions, and configuration/database/password/log paths
cannot be downloaded. Test saving and validation in a browser, including on a
phone. Confirm success appears only after saving.

Submit a harmless test letter for a future hour. After its due time, run:

```sh
/usr/bin/php /srv/futurema/send-due.php
/usr/bin/php /srv/futurema/manage.php status
```

Check inbox/Spam, visible From, original Return-Path and delivery-failure routing.
Rerunning must not resubmit the letter. For immediate testing, use a separate
configuration and database for **both** the form and CLI via `FUTUREMA_CONFIG`;
never advance an ordinary letter's due time.

Preserve existing cron entries and append with `crontab -e`:

```cron
0 * * * * /usr/bin/php /srv/futurema/send-due.php >> /srv/futurema/var/sender.log 2>&1 || /usr/bin/printf 'FutureMa sender failed; inspect sender.log\n' >&2
```

Use the correct absolute CLI PHP path and a monitored cron `MAILTO`. The host's
cron time zone controls runs, while stored UTC timestamps control eligibility.
Check a real scheduled delivery and successful timestamps in `var/sender.log`.
Rotate logs and check disk quota periodically. Backups remain [manual](BACKUPS.md).

## Update or remove

Back up first. Preserve private configuration, data, backups, credentials and
the published server configuration. Pull reviewed code, run checks and publish
updated public files without overwriting configured paths. For database changes,
stop all writers using the [recovery procedure](BACKUPS.md#restore). This version
has no schema migrations; future migrations need their own rollback steps.
Never clean ignored files from a deployment.

To remove the service, block this site, remove only its sender job and wait for
active writers. Retain private data and backups until deliberately deleting them.
