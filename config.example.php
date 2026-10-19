<?php
declare(strict_types=1);

// Copy to config.php OUTSIDE the public directory; use your real private paths.
return [
    'database' => '/home/ACCOUNT/futurema/var/letters.sqlite',
    'recipient' => 'you@example.org',
    'envelope_sender' => 'bounces@example.org',
    // The default visible sender is the current OS account at gethostname().
    // Verify that identity on your host before enabling delivery.
    // 'visible_sender' => 'letters@example.org',
    // 'transport' => 'sendmail',
    // 'transport_path' => '/usr/sbin/sendmail',
    // 'timeout' => 30,
    // 'lock' => '/home/ACCOUNT/futurema/var/letters.sqlite.lock',
    // 'maintenance' => '/home/ACCOUNT/futurema/var/maintenance',
    // Optional SMTP: explicitly select msmtp and its private configuration.
    // 'transport' => 'msmtp',
    // 'transport_path' => '/usr/bin/msmtp',
    // 'msmtp_config' => '/home/ACCOUNT/.config/futurema/msmtp.conf',
];
