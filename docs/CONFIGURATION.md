# Configuration

Copy `config.example.php` to `config.php` beside private `app.php`. Keep it out
of the document root and readable only by the application user/administrators.
All paths must be absolute; parent directories must exist.

```php
<?php
return [
    'database' => '/srv/futurema/var/letters.sqlite',
    'recipient' => 'you@example.org',
    'envelope_sender' => 'bounces@example.org',
];
```

| Key | Default | Purpose |
| --- | --- | --- |
| `database` | Required | Private SQLite file |
| `recipient` | Required | Single recipient, resolved at send time |
| `envelope_sender` | Required | Monitored bounce destination |
| `visible_sender` | OS username + `@` + hostname | Optional visible From; its domain also forms Message-ID |
| `transport` | `sendmail` | `sendmail` or `msmtp`; no automatic fallback |
| `transport_path` | `/usr/sbin/sendmail` or `/usr/bin/msmtp` | Absolute executable path |
| `timeout` | `30` | Transport deadline in seconds, integer 1–120 |
| `lock` | Database path + `.lock` | Shared sender/recovery lock |
| `maintenance` | `maintenance` beside database | Marker that blocks saves and sending |
| `msmtp_config` | Required for `msmtp` | Private SMTP configuration file |

Set the process environment variable `FUTUREMA_CONFIG` to select a different
private config, such as for isolated testing or recovery. Set it for both web
PHP and CLI when they should use the same alternate database.

Validate the resolved visible sender on your host:

```sh
php -r 'require "app.php"; echo FutureMa\config()["visible_sender"], PHP_EOL;'
```

It must be a valid address accepted by the mail system. Changing the envelope
sender does not change visible From or Message-ID. Recipient changes apply to
all unsent letters; retain previous mailbox history for recovery. Do not add
Return-Path manually or use Reply-To as the bounce destination.

## Optional SMTP

Install msmtp separately and select it explicitly with `transport`,
`transport_path` and `msmtp_config`. Keep SMTP settings and credentials out of
application configuration and source control. Example private msmtp file:

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

Replace the credential-helper placeholder, restrict the file to its owner, and
verify the provider allows your envelope sender. Configure your sender domain's
mail authentication with your provider if required.
