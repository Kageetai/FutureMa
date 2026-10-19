<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Give the entire transport tree its own process group before executing anything.
if (!function_exists('posix_setsid') || !function_exists('pcntl_exec') || posix_setsid() < 0 || !isset($argv[1])) {
    fwrite(STDERR, "Cannot isolate transport process group.\n");
    exit(1);
}
pcntl_exec($argv[1], array_slice($argv, 2), getenv());
fwrite(STDERR, "Cannot execute transport.\n");
exit(1);
