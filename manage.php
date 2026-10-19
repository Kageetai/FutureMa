<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/app.php';

try {
    $config = FutureMa\config();
    $command = $argv[1] ?? '';
    if (!in_array($command, ['init', 'status', 'backup', 'hold-pending', 'reconcile', 'mark-submitted', 'retry'], true)) {
        throw new InvalidArgumentException('Usage: php manage.php init|status|backup DIR|hold-pending|reconcile CURRENT_DB|mark-submitted ID UNIX_TIME|retry ID');
    }
    if ($command !== 'init' && !is_file($config['database'])) {
        throw new RuntimeException('Database missing; initialize it explicitly.');
    }
    if ($command === 'backup') {
        $directory = $argv[2] ?? '';
        if (!str_starts_with($directory, '/')) { throw new InvalidArgumentException('Backup directory must be absolute.'); }
        echo FutureMa\backupDatabase($config['database'], $directory, time()) . "\n";
        exit(0);
    }
    $db = FutureMa\database($config['database']);
    if ($command === 'init') { echo "Database ready.\n"; exit(0); }
    if ($command === 'status') {
        $rows = $db->query('SELECT id, due_at, delivery_timezone, submitted_at, attempts, last_error FROM letters ORDER BY due_at, created_at, id')->fetchAll();
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    if (!is_file($config['maintenance'])) { throw new RuntimeException('Enable maintenance and stop all writers before recovery changes.'); }
    $lock = fopen($config['lock'], 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('A sender is still running.'); }
    switch ($command) {
        case 'hold-pending':
            FutureMa\holdPending($db);
            echo "All unsent letters held pending explicit reconciliation.\n";
            break;
        case 'reconcile':
            echo FutureMa\reconcile($db, $argv[2] ?? '') . " letter(s) reconciled.\n";
            break;
        case 'mark-submitted':
            if (!ctype_digit($argv[3] ?? '')) { throw new InvalidArgumentException('Timestamp must be a positive Unix integer.'); }
            FutureMa\markSubmitted($db, $argv[2] ?? '', (int) $argv[3]);
            echo "Matching letter marked submitted.\n";
            break;
        case 'retry':
            $id = $argv[2] ?? '';
            if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) { throw new InvalidArgumentException('Use the exact letter ID.'); }
            $update = $db->prepare('UPDATE letters SET attempts = 0, last_error = NULL WHERE id = ? AND submitted_at IS NULL');
            $update->execute([$id]);
            if ($update->rowCount() !== 1) { throw new InvalidArgumentException('No matching unsent letter.'); }
            echo "Matching unsent letter enabled for retry.\n";
    }
    flock($lock, LOCK_UN);
    fclose($lock);
} catch (Throwable $error) {
    // Only our own validation messages are safe to print; PDO errors can contain personal text.
    fwrite(STDERR, ($error instanceof InvalidArgumentException ? $error->getMessage()
        : 'Operation failed. Check maintenance, sender lock, paths, permissions and database integrity.') . "\n");
    exit(1);
}
