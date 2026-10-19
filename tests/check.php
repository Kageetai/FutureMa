<?php
declare(strict_types=1);

require_once __DIR__ . '/../app.php';

$passed = 0;
function check(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
}
function rejects(callable $action, string $message): void
{
    try { $action(); } catch (InvalidArgumentException) { check(true, $message); return; }
    check(false, $message);
}

// These cases catch silent date normalization, DST guessing, and server-zone dependence.
$now = strtotime('2026-01-01T00:00:00Z');
$cases = [
    ['2026-07-01', '10:00', 'Europe/Berlin', '2026-07-01T08:00:00Z'],
    ['2026-01-02', '10:00', 'Europe/Berlin', '2026-01-02T09:00:00Z'],
    ['2026-07-01', '10:00', 'America/New_York', '2026-07-01T14:00:00Z'],
    ['2026-01-02', '10:00', 'America/New_York', '2026-01-02T15:00:00Z'],
    ['2026-07-01', '10:00', 'Asia/Tokyo', '2026-07-01T01:00:00Z'],
    ['2026-01-02', '10:00', 'Asia/Tokyo', '2026-01-02T01:00:00Z'],
    ['2026-07-01', '10:00', 'Asia/Kolkata', '2026-07-01T04:30:00Z'],
    ['2026-07-01', '10:00', 'Asia/Kathmandu', '2026-07-01T04:15:00Z'],
];
foreach ($cases as [$date, $hour, $zone, $expected]) {
    check(FutureMa\deliveryInstant($date, $hour, $zone, $now) === strtotime($expected), "$zone UTC conversion");
}
check(FutureMa\deliveryInstant('2026-01-01', '02:00', 'Europe/Berlin', $now) === $now + 3600, 'later today');
foreach ([['2026-02-30', '10:00', 'UTC'], ['2026-1-02', '10:00', 'UTC'],
    ['2026-01-02', '10:01', 'UTC'], ['2026-01-02', '10:00:01', 'UTC'],
    ['2026-01-02', '10:00:00', 'UTC'], ['2026-01-02', '24:00', 'UTC'],
    ['2025-12-31', '10:00', 'UTC'], ['2026-01-01', '00:00', 'UTC'],
    ['2026-01-02', '10:00', 'not/a-zone'], ['2026-03-29', '02:00', 'Europe/Berlin'],
    ['2026-10-25', '02:00', 'Europe/Berlin'], ['2026-03-08', '02:00', 'America/New_York'],
    ['2026-11-01', '01:00', 'America/New_York']] as [$date, $hour, $zone]) {
    rejects(fn() => FutureMa\deliveryInstant($date, $hour, $zone, $now), "reject $date $hour $zone");
}
date_default_timezone_set('Pacific/Honolulu');
check(FutureMa\deliveryInstant('2026-07-01', '10:00', 'Europe/Berlin', $now) === 1782892800, 'server zone ignored');

$directory = sys_get_temp_dir() . '/futurema-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
register_shutdown_function(function () use ($directory): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
});
$db = FutureMa\database($directory . '/letters.sqlite');
$input = ['subject' => 'Dear future me 🌱', 'body' => "Hello\n世界", 'date' => '2026-07-01', 'hour' => '10:00', 'timezone' => 'Europe/Berlin'];
$key = bin2hex(random_bytes(32));
$letter = FutureMa\saveLetter($db, $input, $key, $now);
check($letter['due_at'] === 1782892800, 'persist UTC instant');
check($letter['delivery_timezone'] === 'Europe/Berlin', 'persist selected zone');
check(FutureMa\saveLetter($db, $input, $key, $now)['id'] === $letter['id'], 'submission key idempotent');
$reopened = FutureMa\database($directory . '/letters.sqlite');
check($reopened->query('SELECT body FROM letters')->fetchColumn() === "Hello\n世界", 'persistent Unicode');
check((int) $db->query('SELECT COUNT(*) FROM letters')->fetchColumn() === 1, 'no duplicate rows');
foreach (['subject' => ['', str_repeat('x', 201), "Hi\r\nBcc: bad@example.org", "bad\0text", "\xff"],
    'body' => ['', '   ', str_repeat('🌱', 100001), "\xff", "bad\0text"],
    'date' => [[], '2026-02-30'], 'hour' => ['10:15'], 'timezone' => ['bad']] as $field => $values) {
    foreach ($values as $value) {
        rejects(fn() => FutureMa\saveLetter($db, array_replace($input, [$field => $value]), bin2hex(random_bytes(32)), $now), "invalid $field");
    }
}
$large = FutureMa\saveLetter($db, array_replace($input, ['subject' => str_repeat('🌱', 200), 'body' => str_repeat('🌱', 100000)]), bin2hex(random_bytes(32)), $now);
check(mb_strlen($large['body']) === 100000, 'maximum Unicode character count');
rejects(fn() => FutureMa\saveLetter($db, $input, 'bad', $now), 'invalid key');
$db->exec('PRAGMA query_only = ON');
try { FutureMa\saveLetter($db, $input, bin2hex(random_bytes(32)), $now); check(false, 'failed database write'); }
catch (PDOException) { check(true, 'failed write throws without confirmation'); }
$db->exec('PRAGMA query_only = OFF');

// These checks catch unvalidated configuration and accidental SMTP fallback.
$config = ['database' => $directory . '/mail.sqlite', 'recipient' => 'reader@example.org',
    'envelope_sender' => 'bounces@example.org', 'visible_sender' => 'writer@example.net'];
$settings = FutureMa\validateConfig($config);
check($settings['transport'] === 'sendmail', 'default local mail');
check(FutureMa\transportCommand($settings) === ['/usr/sbin/sendmail', '-f', 'bounces@example.org', '-t', '-i'], 'fixed separate envelope arguments');
foreach (['envelope_sender', 'recipient', 'visible_sender'] as $field) {
    foreach (['', "bad\r\nBcc: x@example.org", 'bad', '-x@example.org'] as $value) {
        rejects(fn() => FutureMa\validateConfig(array_replace($config, [$field => $value])), "invalid $field");
    }
}
$missing = $config;
unset($missing['envelope_sender']);
rejects(fn() => FutureMa\validateConfig($missing), 'required envelope sender');
$defaultIdentity = $config;
unset($defaultIdentity['visible_sender']);
$defaultIdentity = FutureMa\validateConfig($defaultIdentity);
check(filter_var($defaultIdentity['visible_sender'], FILTER_VALIDATE_EMAIL) !== false, 'resolved default identity');
$smtp = FutureMa\validateConfig(array_replace($config, ['transport' => 'msmtp', 'msmtp_config' => $directory . '/smtp', 'transport_path' => '/usr/bin/msmtp']));
check(FutureMa\transportCommand($smtp) === ['/usr/bin/msmtp', '--file=' . $directory . '/smtp', '--from=bounces@example.org', '-t', '-i'], 'explicit SMTP command');
rejects(fn() => FutureMa\validateConfig(array_replace($config, ['transport' => 'smtp'])), 'unknown transport');
rejects(fn() => FutureMa\validateConfig(array_replace($config, ['transport' => 'msmtp'])), 'SMTP requires private config');

// The real queue and SQLite state run against a fake external handoff only.
$mailDb = FutureMa\database($settings['database']);
$ids = [];
for ($i = 0; $i < 8; $i++) {
    $record = FutureMa\saveLetter($mailDb, $input, bin2hex(random_bytes(32)), $now + $i);
    $ids[] = $record['id'];
}
$delivered = [];
$accept = function (array $command, string $message, int $timeout) use (&$delivered): array {
    $delivered[] = $message;
    return ['exit' => 0, 'stderr' => ''];
};
check(FutureMa\sendDue($mailDb, $settings, 1782892799, $accept) === 0 && count($delivered) === 0, 'never submit early');
check(FutureMa\sendDue($mailDb, $settings, 1782892800, $accept) === 0 && count($delivered) === 8, 'drain more than five letters');
check(FutureMa\sendDue($mailDb, $settings, 1782896400, $accept) === 0 && count($delivered) === 8, 'no ordinary resubmission');
check((int) $mailDb->query('SELECT COUNT(*) FROM letters WHERE submitted_at IS NOT NULL')->fetchColumn() === 8, 'successful handoffs recorded');
$mime = $delivered[0];
check(str_contains($mime, "Message-ID: <{$ids[0]}@example.net>\r\n"), 'stable Message-ID');
check(str_contains($mime, "From: writer@example.net\r\n"), 'visible sender');
check(!str_contains($mime, 'Return-Path:'), 'envelope managed by transport');
check(str_contains($mime, base64_encode("Hello\r\n世界")), 'UTF-8 MIME body');
$changed = FutureMa\validateConfig(array_replace($config, ['envelope_sender' => 'new@example.com']));
check(FutureMa\message($letter, $settings, $now) === FutureMa\message($letter, $changed, $now), 'envelope change preserves MIME identity');

$mailDb->exec('UPDATE letters SET submitted_at = NULL');
$count = 0;
$rateLimit = function () use (&$count): array {
    $count++;
    return ['exit' => 1, 'stderr' => 'You are sending too many mails. Please wait.'];
};
for ($i = 0; $i < 7; $i++) {
    check(FutureMa\sendDue($mailDb, $settings, 1782892800, $rateLimit) === 1, 'rate limit exits nonzero');
}
check($count === 7, 'rate limit stops the queue');
check((int) $mailDb->query('SELECT SUM(attempts) FROM letters')->fetchColumn() === 0, 'rate limit never consumes retries');
check(FutureMa\sendDue($mailDb, $settings, 1782896400, $accept) === 0, 'rate limit recovers');
$mailDb->exec('UPDATE letters SET submitted_at = NULL');
$failed = fn() => ['exit' => 1, 'stderr' => 'Some other failure'];
for ($i = 0; $i < 5; $i++) {
    check(FutureMa\sendDue($mailDb, $settings, 1782896400, $failed) === 1, 'unclassified failure nonzero');
}
$first = $mailDb->query('SELECT * FROM letters ORDER BY due_at, created_at, id LIMIT 1')->fetch();
check($first['attempts'] === 5 && $first['submitted_at'] === null, 'failed letter retained with exhausted allowance');
$before = count($delivered);
check(FutureMa\sendDue($mailDb, $settings, 1782896400, $accept) === 1, 'exhausted letters reported');
check(count($delivered) === $before + 7, 'exhausted letter does not block later queue');
$mailDb->exec('UPDATE letters SET attempts = 0, submitted_at = NULL');
$sequence = 0;
$partial = function () use (&$sequence): array {
    return ++$sequence === 2 ? ['exit' => 75, 'stderr' => 'temporary error'] : ['exit' => 0, 'stderr' => ''];
};
check(FutureMa\sendDue($mailDb, $settings, 1782896400, $partial) === 1 && $sequence === 2, 'temporary failure stops run after prior success');
check((int) $mailDb->query('SELECT COUNT(*) FROM letters WHERE submitted_at IS NOT NULL')->fetchColumn() === 1, 'prior acceptance preserved');
$before = count($delivered);
check(FutureMa\sendDue($mailDb, $settings, 1782900000, $accept) === 0 && count($delivered) === $before + 7, 'next run retries only pending');

// A real child process catches stdin/stderr deadlocks and verifies timeout behavior.
$capture = $directory . '/capture';
$transport = [PHP_BINARY, __DIR__ . '/transport.php', 'capture', $capture];
$result = FutureMa\runTransport($transport, str_repeat('🌱', 100000), 5);
check($result['exit'] === 0 && file_get_contents($capture) === str_repeat('🌱', 100000), 'real process consumes entire message');
$result = FutureMa\runTransport([PHP_BINARY, __DIR__ . '/transport.php', 'flood'], str_repeat('x', 400000), 5);
check($result['exit'] === 1 && strlen($result['stderr']) <= 2048, 'bounded diagnostics without deadlock');
$started = microtime(true);
$result = FutureMa\runTransport([PHP_BINARY, __DIR__ . '/transport.php', 'sleep'], 'test', 1);
check($result['exit'] !== 0 && microtime(true) - $started < 3, 'transport deadline enforced');
$escaped = $directory . '/escaped-child';
FutureMa\runTransport([PHP_BINARY, __DIR__ . '/transport.php', 'spawn', $escaped], 'test', 1);
usleep(700000);
check(!file_exists($escaped), 'timeout kills transport descendants before releasing sender lock');
$lock = fopen($settings['lock'], 'c');
flock($lock, LOCK_EX);
check(FutureMa\sendDue($mailDb, $settings, 1782900000, $accept) === 0, 'overlapping run exits without sending');
flock($lock, LOCK_UN);
fclose($lock);

// A wrong UTC comparison would either submit early or miss the first hourly run.
foreach ($cases as $index => [$date, $hour, $zone, $expected]) {
    $scheduleSettings = FutureMa\validateConfig(array_replace($config, ['database' => $directory . '/schedule-' . $index . '.sqlite']));
    $scheduleDb = FutureMa\database($scheduleSettings['database']);
    $scheduled = FutureMa\saveLetter($scheduleDb, array_replace($input, ['date' => $date, 'hour' => $hour, 'timezone' => $zone]), bin2hex(random_bytes(32)), $now);
    $firstRun = (int) (ceil(strtotime($expected) / 3600) * 3600);
    $before = count($delivered);
    FutureMa\sendDue($scheduleDb, $scheduleSettings, $firstRun - 3600, $accept);
    check(count($delivered) === $before, "$zone not submitted on preceding hourly run");
    FutureMa\sendDue($scheduleDb, $scheduleSettings, $firstRun, $accept);
    check(count($delivered) === $before + 1, "$zone submitted on first eligible hourly run");
    check($scheduleDb->query('SELECT due_at FROM letters')->fetchColumn() === $scheduled['due_at'], 'sending does not move stored instant');
}

require __DIR__ . '/http.php';
$httpConfig = FutureMa\validateConfig(array_replace($config, ['database' => $directory . '/http.sqlite']));
httpChecks($directory, $httpConfig);

// Backup must be a real consistent snapshot; reconciliation must prevent replay.
$backupDir = $directory . '/backups';
mkdir($backupDir, 0700);
$snapshot = FutureMa\backupDatabase($settings['database'], $backupDir, $now);
$backupDb = new PDO('sqlite:' . $snapshot);
check($backupDb->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'SQLite backup integrity');
check((int) $backupDb->query('SELECT COUNT(*) FROM letters')->fetchColumn() === 8, 'snapshot preserves letters');
$mailDb->exec('UPDATE letters SET submitted_at = NULL');
$stale = FutureMa\backupDatabase($settings['database'], $backupDir, $now + 86400);
FutureMa\sendDue($mailDb, $settings, 1782903600, $accept);
$restore = FutureMa\database($stale);
check((int) $restore->query('SELECT COUNT(*) FROM letters WHERE submitted_at IS NOT NULL')->fetchColumn() === 0, 'backup predates handoff');
FutureMa\holdPending($restore);
check((int) $restore->query('SELECT COUNT(*) FROM letters WHERE attempts >= 5')->fetchColumn() === 8, 'uncertain letters held');
check(FutureMa\reconcile($restore, $settings['database']) === 8, 'readable current history reconciles');
$before = count($delivered);
check(FutureMa\sendDue($restore, $settings, 1782903600, $accept) === 0 && count($delivered) === $before, 'reconciled restored letters not resent');
$restore->exec('UPDATE letters SET submitted_at = NULL, attempts = 0');
FutureMa\holdPending($restore);
FutureMa\markSubmitted($restore, $ids[0], 1782903600);
check((int) $restore->query('SELECT COUNT(*) FROM letters WHERE submitted_at IS NOT NULL')->fetchColumn() === 1, 'mail-header reconciliation marks only exact ID');
check((int) $restore->query('SELECT COUNT(*) FROM letters WHERE submitted_at IS NULL AND attempts >= 5')->fetchColumn() === 7, 'incomplete mailbox history remains held');
rejects(fn() => FutureMa\markSubmitted($restore, 'bad', $now), 'reject malformed recovery ID');
for ($day = 2; $day <= 32; $day++) {
    FutureMa\backupDatabase($settings['database'], $backupDir, $now + 86400 * $day);
}
check(count(glob($backupDir . '/futurema-*.sqlite')) === 30, 'retain thirty daily snapshots');
unset($backupDb, $restore);
foreach (glob($backupDir . '/*') as $file) { unlink($file); }
rmdir($backupDir);

echo "PASS: $passed checks\n";
