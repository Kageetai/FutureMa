<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/app.php';

try {
    $config = FutureMa\config();
    if (!is_file($config['database'])) { throw new RuntimeException('Database missing.'); }
    $result = FutureMa\sendDue(FutureMa\database($config['database']), $config, time());
    if ($result === 0) { echo 'Sender completed at ' . gmdate('c') . ".\n"; }
    exit($result);
} catch (Throwable) {
    fwrite(STDERR, "Sender failed. Check private configuration, maintenance state, storage permissions and free space.\n");
    exit(1);
}
