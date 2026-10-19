<?php
declare(strict_types=1);

namespace FutureMa;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

function timezones(): array
{
    return DateTimeZone::listIdentifiers();
}

function deliveryInstant(string $date, string $hour, string $zone, int $now): int
{
    if (!in_array($zone, timezones(), true)) {
        throw new InvalidArgumentException('Choose a valid IANA time zone.');
    }
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)
        || !preg_match('/\A(?:[01]\d|2[0-3]):00\z/', $hour)) {
        throw new InvalidArgumentException('Choose a valid date and whole hour (minutes must be 00).');
    }
    $local = "$date $hour";
    $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, new DateTimeZone('UTC'));
    if (!$wall || $wall->format('Y-m-d H:i') !== $local) {
        throw new InvalidArgumentException('Choose a valid calendar date.');
    }
    // Count matching instants instead of accepting PHP’s DST normalization/guess.
    $tz = new DateTimeZone($zone);
    $stamp = $wall->getTimestamp();
    $offsets = array_unique(array_column($tz->getTransitions($stamp - 172800, $stamp + 172800), 'offset'));
    $matches = [];
    foreach ($offsets as $offset) {
        $candidate = $stamp - $offset;
        if ((new DateTimeImmutable('@' . $candidate))->setTimezone($tz)->format('Y-m-d H:i') === $local) {
            $matches[] = $candidate;
        }
    }
    if (count($matches) !== 1) {
        throw new InvalidArgumentException('This local hour is missing or occurs twice because the clocks change. Choose another hour.');
    }
    if ($matches[0] <= $now) {
        throw new InvalidArgumentException('Choose a delivery time strictly in the future.');
    }
    return $matches[0];
}

function database(string $path): PDO
{
    umask(0077);
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('CREATE TABLE IF NOT EXISTS letters (
        id TEXT PRIMARY KEY, created_at INTEGER NOT NULL, due_at INTEGER NOT NULL,
        delivery_timezone TEXT NOT NULL, subject TEXT NOT NULL, body TEXT NOT NULL,
        submission_key TEXT NOT NULL UNIQUE, submitted_at INTEGER,
        attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS letters_due ON letters(due_at) WHERE submitted_at IS NULL');
    return $db;
}

function saveLetter(PDO $db, array $input, string $key, int $now): array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) {
        throw new InvalidArgumentException('The form has expired. Reload and try again.');
    }
    foreach (['subject', 'body', 'date', 'hour', 'timezone'] as $field) {
        if (!isset($input[$field]) || !is_string($input[$field]) || !mb_check_encoding($input[$field], 'UTF-8')) {
            throw new InvalidArgumentException('All fields must contain valid UTF-8 text.');
        }
    }
    $subject = $input['subject'];
    $body = $input['body'];
    if (trim($subject) === '' || mb_strlen($subject) > 200 || preg_match('/[\x00-\x1f\x7f]/', $subject)) {
        throw new InvalidArgumentException('Subject must be 1–200 characters without line breaks or control characters.');
    }
    if (trim($body) === '' || mb_strlen($body) > 100000 || str_contains($body, "\0")) {
        throw new InvalidArgumentException('Letter must be 1–100,000 characters without null bytes.');
    }
    $due = deliveryInstant($input['date'], $input['hour'], $input['timezone'], $now);
    $id = bin2hex(random_bytes(16));
    $statement = $db->prepare('INSERT INTO letters
        (id, created_at, due_at, delivery_timezone, subject, body, submission_key)
        VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(submission_key) DO NOTHING');
    $statement->execute([$id, $now, $due, $input['timezone'], $subject, $body, $key]);
    // Autocommit has succeeded before returning any confirmation.
    $query = $db->prepare('SELECT * FROM letters WHERE submission_key = ?');
    $query->execute([$key]);
    return $query->fetch();
}

function address(mixed $value): string
{
    if (!is_string($value) || preg_match('/[\x00-\x20\x7f]/', $value)
        || str_starts_with($value, '-') || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Mail addresses must be valid single addresses without whitespace.');
    }
    return $value;
}

function validateConfig(array $config): array
{
    if (!isset($config['visible_sender'])) {
        $user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $config['visible_sender'] = ($user['name'] ?? get_current_user()) . '@' . gethostname();
    }
    foreach (['recipient', 'envelope_sender', 'visible_sender'] as $field) {
        $config[$field] = address($config[$field] ?? null);
    }
    $config += ['transport' => 'sendmail', 'timeout' => 30];
    if (!in_array($config['transport'], ['sendmail', 'msmtp'], true)) {
        throw new InvalidArgumentException('Transport must be sendmail or msmtp.');
    }
    $config += ['transport_path' => $config['transport'] === 'sendmail' ? '/usr/sbin/sendmail' : '/usr/bin/msmtp'];
    $config += ['lock' => ($config['database'] ?? '') . '.lock',
        'maintenance' => dirname($config['database'] ?? '') . '/maintenance'];
    $paths = ['database', 'lock', 'maintenance', 'transport_path'];
    if ($config['transport'] === 'msmtp') { $paths[] = 'msmtp_config'; }
    foreach ($paths as $field) {
        if (!isset($config[$field]) || !is_string($config[$field]) || !str_starts_with($config[$field], '/')
            || preg_match('/[\x00-\x1f\x7f]/', $config[$field])) {
            throw new InvalidArgumentException("$field must be an absolute private path.");
        }
    }
    if (!is_int($config['timeout']) || $config['timeout'] < 1 || $config['timeout'] > 120) {
        throw new InvalidArgumentException('Timeout must be an integer from 1 to 120 seconds.');
    }
    return $config;
}

function config(): array
{
    $path = getenv('FUTUREMA_CONFIG') ?: __DIR__ . '/config.php';
    if (!is_file($path)) { throw new RuntimeException('Private configuration is missing.'); }
    $config = require $path;
    if (!is_array($config)) { throw new RuntimeException('Configuration must return an array.'); }
    return validateConfig($config);
}

function transportCommand(array $config): array
{
    return $config['transport'] === 'sendmail'
        ? [$config['transport_path'], '-f', $config['envelope_sender'], '-t', '-i']
        : [$config['transport_path'], '--file=' . $config['msmtp_config'], '--from=' . $config['envelope_sender'], '-t', '-i'];
}

function message(array $letter, array $config, int $now): string
{
    $sender = address($config['visible_sender']);
    $recipient = address($config['recipient']);
    if (!preg_match('/\A[a-f0-9]{32}\z/', $letter['id']) || preg_match('/[\x00-\x1f\x7f]/', $letter['subject'])) {
        throw new InvalidArgumentException('Invalid stored message headers.');
    }
    $domain = substr($sender, strrpos($sender, '@') + 1);
    $subject = mb_encode_mimeheader($letter['subject'], 'UTF-8', 'B', "\r\n", 9);
    $body = preg_replace('/\r\n|\r|\n/', "\r\n", $letter['body']);
    return "From: $sender\r\nTo: $recipient\r\nSubject: $subject\r\n"
        . 'Date: ' . gmdate('D, d M Y H:i:s +0000', $now) . "\r\n"
        . "Message-ID: <{$letter['id']}@$domain>\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");
}

function runTransport(array $command, string $message, int $timeout): array
{
    if (!function_exists('posix_kill')) { return ['exit' => -1, 'stderr' => 'POSIX support is required.']; }
    $process = @proc_open([PHP_BINARY, __DIR__ . '/transport-exec.php', ...$command],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { return ['exit' => -1, 'stderr' => 'Transport could not start.']; }
    foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
    $deadline = microtime(true) + $timeout;
    $offset = 0;
    $error = '';
    $exit = -1;
    $timedOut = false;
    do {
        if (isset($pipes[0])) {
            $written = @fwrite($pipes[0], substr($message, $offset, 8192));
            if ($written === false || ($offset += $written) >= strlen($message)) {
                fclose($pipes[0]); unset($pipes[0]);
            }
        }
        // Drain both outputs while writing stdin; neither side can fill a pipe.
        foreach ([1, 2] as $index) {
            $chunk = fread($pipes[$index], 8192);
            if ($index === 2 && $chunk !== false) { $error .= substr($chunk, 0, max(0, 2048 - strlen($error))); }
        }
        $status = proc_get_status($process);
        if (!$status['running']) { $exit = $status['exitcode']; break; }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            posix_kill(-$status['pid'], 9);
            proc_terminate($process, 9);
            break;
        }
        usleep(1000);
    } while (true);
    foreach ([1, 2] as $index) {
        $tail = stream_get_contents($pipes[$index]);
        if ($index === 2 && $tail !== false) { $error .= substr($tail, 0, max(0, 2048 - strlen($error))); }
    }
    foreach ($pipes as $pipe) { fclose($pipe); }
    proc_close($process);
    if ($timedOut) { return ['exit' => -1, 'stderr' => 'Transport timed out.']; }
    if ($exit === 0 && $offset < strlen($message)) { return ['exit' => -1, 'stderr' => 'Transport closed input early.']; }
    return ['exit' => $exit, 'stderr' => $error];
}

function sendDue(PDO $db, array $config, int $now, ?callable $transport = null): int
{
    umask(0077);
    $lock = fopen($config['lock'], 'c');
    if (!$lock) { throw new RuntimeException('Cannot open sender lock.'); }
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return 0; }
    try {
        if (is_file($config['maintenance'])) { throw new RuntimeException('Maintenance is active; sender paused.'); }
        $exhausted = (int) $db->query('SELECT COUNT(*) FROM letters WHERE submitted_at IS NULL AND attempts >= 5')->fetchColumn();
        $failed = $exhausted > 0;
        if ($failed) { fwrite(STDERR, "$exhausted letter(s) need manual intervention (retry allowance exhausted).\n"); }
        $query = $db->prepare('SELECT * FROM letters WHERE submitted_at IS NULL AND attempts < 5 AND due_at <= ? ORDER BY due_at, created_at, id');
        $query->execute([$now]);
        $letters = $query->fetchAll();
        $query->closeCursor();
        $transport ??= __NAMESPACE__ . '\\runTransport';
        foreach ($letters as $letter) {
            if (is_file($config['maintenance'])) { throw new RuntimeException('Maintenance is active; sender paused.'); }
            try {
                $result = $transport(transportCommand($config), message($letter, $config, $now), $config['timeout']);
            } catch (\Throwable) {
                $result = ['exit' => -1, 'stderr' => 'Transport or message construction failed.'];
            }
            if ($result['exit'] === 0) {
                $update = $db->prepare('UPDATE letters SET submitted_at = ?, last_error = NULL WHERE id = ?');
                $update->execute([$now, $letter['id']]);
                continue;
            }
            $limited = $config['transport'] === 'sendmail' && $result['exit'] === 1
                && str_starts_with($result['stderr'], 'You are sending too many mails.');
            // Raw transport output may echo a letter or credentials. Retain only safe diagnostics.
            $diagnostic = $limited ? 'Rate limited; deferred without consuming retry allowance.'
                : 'Transport failed (exit ' . (int) $result['exit'] . '); retry allowance consumed.';
            $update = $db->prepare('UPDATE letters SET attempts = attempts + ?, last_error = ? WHERE id = ?');
            $update->execute([$limited ? 0 : 1, $diagnostic, $letter['id']]);
            fwrite(STDERR, "Letter {$letter['id']}: $diagnostic\n");
            $failed = true;
            break;
        }
        return $failed ? 1 : 0;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function backupDatabase(string $source, string $directory, int $now): string
{
    umask(0077);
    if (!is_file($source) || !is_dir($directory)) {
        throw new RuntimeException('Source database and backup directory must already exist.');
    }
    $path = $directory . '/futurema-' . gmdate('Y-m-d', $now) . '.sqlite';
    $temporary = tempnam($directory, '.backup-');
    try {
        $input = new \SQLite3($source, SQLITE3_OPEN_READONLY);
        $input->busyTimeout(5000);
        $output = new \SQLite3($temporary);
        $output->busyTimeout(5000);
        if (!$input->backup($output) || $output->querySingle('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('SQLite backup or integrity check failed.');
        }
        $output->close();
        $input->close();
        if (!rename($temporary, $path)) { throw new RuntimeException('Cannot install backup.'); }
        $backups = array_filter(glob($directory . '/futurema-*.sqlite'),
            fn(string $file): bool => preg_match('/\Afuturema-\d{4}-\d{2}-\d{2}\.sqlite\z/', basename($file)) === 1);
        rsort($backups, SORT_STRING);
        foreach (array_slice($backups, 30) as $old) {
            if (!unlink($old)) { throw new RuntimeException('Cannot prune old backup.'); }
        }
        return $path;
    } finally {
        if (is_file($temporary)) { unlink($temporary); }
    }
}

function holdPending(PDO $db): void
{
    $db->exec("UPDATE letters SET attempts = 5, last_error = 'Recovery hold: submission history uncertain.' WHERE submitted_at IS NULL");
}

function markSubmitted(PDO $db, string $id, int $timestamp): void
{
    if (!preg_match('/\A[a-f0-9]{32}\z/', $id) || $timestamp <= 0) {
        throw new InvalidArgumentException('Use the exact 32-character letter ID and a positive UTC Unix timestamp.');
    }
    $update = $db->prepare('UPDATE letters SET submitted_at = ?, last_error = NULL WHERE id = ? AND submitted_at IS NULL');
    $update->execute([$timestamp, $id]);
    if ($update->rowCount() !== 1) { throw new InvalidArgumentException('ID missing or already marked submitted.'); }
}

function reconcile(PDO $restored, string $currentPath): int
{
    if (!is_file($currentPath)) { throw new InvalidArgumentException('Current database must exist.'); }
    $current = new PDO('sqlite:file:' . $currentPath . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rows = $current->query('SELECT id, submitted_at FROM letters WHERE submitted_at IS NOT NULL');
    $update = $restored->prepare('UPDATE letters SET submitted_at = ?, last_error = NULL WHERE id = ? AND submitted_at IS NULL');
    $count = 0;
    $restored->beginTransaction();
    try {
        foreach ($rows as $row) {
            $update->execute([$row['submitted_at'], $row['id']]);
            $count += $update->rowCount();
        }
        $restored->commit();
    } catch (\Throwable $error) {
        $restored->rollBack();
        throw $error;
    }
    return $count;
}
