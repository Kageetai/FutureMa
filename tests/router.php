<?php
declare(strict_types=1);

// Test Apache's authenticated-user boundary without requiring Apache locally.
if (($_SERVER['PHP_AUTH_USER'] ?? '') === 'owner' && ($_SERVER['PHP_AUTH_PW'] ?? '') === 'test-password') {
    $_SERVER['REMOTE_USER'] = 'owner';
}
$_SERVER['HTTPS'] = 'on';
if ($_SERVER['REQUEST_URI'] === '/sender-test') { require __DIR__ . '/../send-due.php'; return; }
require __DIR__ . '/../public/index.php';
