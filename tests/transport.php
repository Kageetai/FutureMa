<?php
declare(strict_types=1);

if ($argv[1] === 'sleep') { sleep(10); exit(0); }
if ($argv[1] === 'spawn') {
    $child = pcntl_fork();
    if ($child === 0) { usleep(1500000); file_put_contents($argv[2], 'escaped'); exit(0); }
    sleep(10);
    exit(0);
}
if ($argv[1] === 'flood') {
    fwrite(STDERR, str_repeat('error ', 40000));
    stream_get_contents(STDIN);
    exit(1);
}
file_put_contents($argv[2], stream_get_contents(STDIN));
