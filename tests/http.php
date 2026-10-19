<?php
declare(strict_types=1);

function httpChecks(string $directory, array $config): void
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $endpoint = stream_socket_get_name($socket, false);
    fclose($socket);
    $configPath = $directory . '/config.php';
    file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');
    $log = $directory . '/http.log';
    $server = proc_open([PHP_BINARY, '-d', 'post_max_size=3M', '-d', 'display_errors=0',
        '-d', 'session.save_path=' . $directory, '-S', $endpoint, __DIR__ . '/router.php'],
        [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, null,
        array_merge(getenv(), ['FUTUREMA_CONFIG' => $configPath]));
    try {
        $ready = false;
        for ($i = 0; $i < 100; $i++) {
            $probe = @stream_socket_client('tcp://' . $endpoint, $errno, $errstr, 0.05);
            if ($probe) { fclose($probe); $ready = true; break; }
            usleep(10000);
        }
        check($ready, 'HTTP server starts');
        $request = function (string $method = 'GET', ?string $body = null, string $cookie = '', bool $auth = true, string $path = '/') use ($endpoint): array {
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            if ($auth) { $headers[] = 'Authorization: Basic ' . base64_encode('owner:test-password'); }
            if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
            $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
                'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
            $text = file_get_contents('http://' . $endpoint . $path, false, $context);
            return [$text, $http_response_header];
        };
        [$body, $headers] = $request(auth: false);
        check(str_contains($headers[0], '401'), 'unauthenticated GET denied');
        [$body, $headers] = $request('POST', 'subject=secret', auth: false);
        check(str_contains($headers[0], '401'), 'unauthenticated POST denied');
        [$body, $headers] = $request();
        check(str_contains($headers[0], '200'), 'authenticated form available');
        check(in_array('Cache-Control: no-store', $headers, true), 'private page not cached');
        $cookie = '';
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                check(str_contains($header, 'secure') && str_contains($header, 'HttpOnly') && str_contains($header, 'SameSite=Strict'), 'secure session attributes');
                $cookie = explode(';', substr($header, 12))[0];
            }
        }
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $csrf);
        preg_match('/name="submission_key" value="([a-f0-9]+)"/', $body, $key);
        check(isset($csrf[1], $key[1]), 'CSRF and submission key present');
        $form = ['subject' => '<script>alert(1)</script>', 'body' => str_repeat('🌱', 100000),
            'date' => '2099-07-01', 'hour' => '10:00', 'timezone' => 'Europe/Berlin',
            'csrf' => $csrf[1], 'submission_key' => $key[1]];
        $bad = $form; unset($bad['csrf']);
        [$body, $headers] = $request('POST', http_build_query($bad), $cookie);
        check(str_contains($headers[0], '403'), 'missing CSRF denied');
        $bad['csrf'] = 'bad';
        [$body, $headers] = $request('POST', http_build_query($bad), $cookie);
        check(str_contains($headers[0], '403'), 'invalid CSRF denied');
        [$body, $headers] = $request('POST', http_build_query($form), $cookie);
        check(str_contains($headers[0], '303'), 'committed save redirects');
        $db = FutureMa\database($config['database']);
        check($db->query('SELECT body FROM letters')->fetchColumn() === str_repeat('🌱', 100000), 'complete encoded Unicode form persisted');
        [$body, $headers] = $request('POST', http_build_query($form), $cookie);
        check(str_contains($headers[0], '303') && (int) $db->query('SELECT COUNT(*) FROM letters')->fetchColumn() === 1, 'double POST idempotent');
        [$body, $headers] = $request(cookie: $cookie);
        check(str_contains($body, 'Scheduled for') && str_contains($body, 'Europe/Berlin') && !str_contains($body, '<script>'), 'confirmation omits letter content');
        preg_match('/name="submission_key" value="([a-f0-9]+)"/', $body, $newKey);
        $form['submission_key'] = $newKey[1];
        $form['body'] .= '🌱';
        [$body, $headers] = $request('POST', http_build_query($form), $cookie);
        check(str_contains($headers[0], '422') && str_contains($body, '&lt;script&gt;') && !str_contains($body, '<script>'), 'length error preserves escaped text');
        [$body, $headers] = $request('POST', str_repeat('x', 2097153), $cookie);
        check(str_contains($headers[0], '413') && !str_contains($body, 'Scheduled for'), 'over 2 MiB rejected clearly');
        touch($config['maintenance']);
        [$body, $headers] = $request('POST', http_build_query($form), $cookie);
        check(str_contains($headers[0], '503'), 'maintenance blocks POST');
        unlink($config['maintenance']);
        $form['body'] = 'A letter that cannot be saved';
        // SQLite’s query-only test covers the save failure; a missing parent exercises HTTP failure.
        $broken = $config; $broken['database'] = $directory . '/missing/letters.sqlite';
        file_put_contents($configPath, '<?php return ' . var_export($broken, true) . ';');
        [$body, $headers] = $request('POST', http_build_query($form), $cookie);
        check(str_contains($headers[0], '503') && !str_contains($body, 'Scheduled for'), 'database failure never confirms success');
        check(str_contains($body, $form['body']), 'failed save preserves letter');
        [$body, $headers] = $request(path: '/sender-test');
        check(str_contains($headers[0], '404'), 'sender cannot run over HTTP');
    } finally {
        proc_terminate($server);
        fclose($pipes[0]);
        proc_close($server);
    }
}
