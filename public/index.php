<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

// Apache must authenticate first; PHP_AUTH_USER alone is not proof of authentication.
if (empty($_SERVER['REMOTE_USER'])) {
    header('WWW-Authenticate: Basic realm="FutureMa"');
    http_response_code(401);
    exit('Authentication required.');
}
if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
    http_response_code(403);
    exit('HTTPS is required.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2097152) {
    http_response_code(413);
    exit('The request exceeds 2 MiB. Keep your letter locally and submit a smaller request.');
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST'); http_response_code(405); exit;
}

$app = getenv('FUTUREMA_APP') ?: dirname(__DIR__) . '/app.php';
if (!is_file($app)) { http_response_code(503); exit('Private application path is not configured.'); }
require $app;
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('futurema');
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
if (!session_start()) { http_response_code(503); exit('Session storage is unavailable. Please try again later.'); }
header('Cache-Control: no-store');
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['forms'] ??= [];
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$values = ['subject' => '', 'body' => '', 'date' => '', 'hour' => '10:00', 'timezone' => 'Europe/Berlin'];
if ($post) {
    foreach ($values as $field => $default) { $values[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : ''; }
}
$error = '';
$confirmation = null;
$key = $post && is_string($_POST['submission_key'] ?? null) ? $_POST['submission_key'] : '';
try {
    $config = FutureMa\config();
    if (is_file($config['maintenance'])) {
        http_response_code(503);
        $error = 'Maintenance is in progress. Keep a local copy of your letter and try again later.';
    } elseif ($post) {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])
            || !isset($_SESSION['forms'][$key])) {
            http_response_code(403);
            $error = 'The form has expired or its security token is invalid. Keep your text and reload.';
        } else {
            $letter = FutureMa\saveLetter(FutureMa\database($config['database']), $_POST, $key, time());
            $_SESSION['confirmation'] = ['due_at' => $letter['due_at'], 'timezone' => $letter['delivery_timezone']];
            session_write_close();
            header('Location: ./', true, 303);
            exit;
        }
    } else {
        $confirmation = $_SESSION['confirmation'] ?? null;
        unset($_SESSION['confirmation']);
    }
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    $error = $exception->getMessage();
} catch (Throwable) {
    http_response_code(503);
    $error = 'Your letter could not be saved. Keep a local copy and try again later. Check private configuration and storage.';
}
if (!isset($_SESSION['forms'][$key])) {
    $key = bin2hex(random_bytes(32));
    $_SESSION['forms'][$key] = time();
    if (count($_SESSION['forms']) > 20) { array_shift($_SESSION['forms']); }
}
$csrf = $_SESSION['csrf'];
session_write_close();
$letters = [];
$overviewError = '';
if (isset($config) && !is_file($config['maintenance'])) {
    try {
        $letters = FutureMa\database($config['database'])->query(
            'SELECT subject, body, due_at, delivery_timezone, submitted_at, attempts FROM letters ORDER BY due_at DESC, created_at DESC, id'
        )->fetchAll();
    } catch (Throwable) {
        http_response_code(503);
        $overviewError = 'Your letters could not be loaded. Please try again later.';
    }
}
function escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FutureMa — a letter to your future self</title>
    <style>
        :root { color-scheme: light dark; font: 18px/1.6 system-ui, sans-serif; }
        body { max-width: 46rem; margin: 3rem auto; padding: 0 1.2rem; }
        h1 { line-height: 1.2; margin-bottom: .5rem; }
        label { display: block; font-weight: 600; margin-top: 1.2rem; }
        input, select, textarea, button { font: inherit; box-sizing: border-box; max-width: 100%; padding: .6rem; }
        input[type=text], textarea { width: 100%; }
        textarea { min-height: 20rem; resize: vertical; }
        button { margin-top: 1.5rem; cursor: pointer; }
        :focus-visible { outline: 3px solid #4b88d8; outline-offset: 3px; }
        .notice { border: 2px solid currentColor; border-radius: .3rem; padding: 1rem; }
        .hint { font-size: .9rem; }
        #letters { margin-top: 3rem; }
        .letter { border-top: 1px solid currentColor; padding: 1rem 0; overflow-wrap: anywhere; }
        .letter h3 { margin: 0; }
        summary { cursor: pointer; }
        .letter-body { white-space: pre-wrap; margin-top: 1rem; }
    </style>
</head>
<body>
<main>
    <h1>A letter to your future self</h1>
    <p>Write something you’d like to receive one day. Seal it with a date.</p>
    <?php if ($error !== ''): ?>
        <p class="notice" role="alert"><?= escape($error) ?></p>
    <?php endif; ?>
    <?php if ($confirmation):
        $scheduled = (new DateTimeImmutable('@' . $confirmation['due_at']))->setTimezone(new DateTimeZone($confirmation['timezone'])); ?>
        <p class="notice" role="status">Scheduled for <?= escape($scheduled->format('Y-m-d H:i') . ' ' . $confirmation['timezone']) ?>. Your letter has been saved.</p>
    <?php endif; ?>
    <form method="post" action="./">
        <input type="hidden" name="csrf" value="<?= escape($csrf) ?>">
        <input type="hidden" name="submission_key" value="<?= escape($key) ?>">
        <label for="subject">Subject</label>
        <input id="subject" name="subject" type="text" required value="<?= escape($values['subject']) ?>" aria-describedby="subject-hint">
        <span id="subject-hint" class="hint">1–200 characters; one line.</span>
        <label for="body">Your letter</label>
        <textarea id="body" name="body" required aria-describedby="body-hint"><?= escape($values['body']) ?></textarea>
        <span id="body-hint" class="hint">Plain text, up to 100,000 characters. Your writing and its backups are private to the hosting account, but are not encrypted.</span>
        <label for="date">Delivery date</label>
        <input id="date" name="date" type="date" required value="<?= escape($values['date']) ?>">
        <label for="hour">Delivery hour</label>
        <select id="hour" name="hour" required>
            <?php for ($hour = 0; $hour < 24; $hour++): $option = sprintf('%02d:00', $hour); ?>
                <option value="<?= $option ?>" <?= $values['hour'] === $option ? 'selected' : '' ?>><?= $option ?></option>
            <?php endfor; ?>
        </select>
        <label for="timezone">Delivery time zone</label>
        <select id="timezone" name="timezone" required aria-describedby="schedule-hint">
            <?php foreach (FutureMa\timezones() as $zone): ?>
                <option value="<?= escape($zone) ?>" <?= $values['timezone'] === $zone ? 'selected' : '' ?>><?= escape($zone) ?></option>
            <?php endforeach; ?>
        </select>
        <p id="schedule-hint" class="hint">Delivery is submitted on the first hourly run at or after your chosen time. In zones with half-hour or quarter-hour offsets, this may be up to an hour later. Inbox arrival depends on mail processing. Hours skipped or repeated when clocks change cannot be selected. Travel will not change a saved delivery time.</p>
        <button type="submit">Seal this letter</button>
    </form>
    <?php if (isset($config) && !is_file($config['maintenance'])): ?>
    <section id="letters" aria-labelledby="letters-heading">
        <h2 id="letters-heading">Your letters</h2>
        <p>Past and pending letters, latest scheduled date first. Open a letter to read its contents.</p>
        <?php if ($overviewError !== ''): ?>
            <p class="notice" role="alert"><?= escape($overviewError) ?></p>
        <?php elseif (!$letters): ?>
            <p>No letters yet.</p>
        <?php else: ?>
            <?php foreach ($letters as $letter):
                $scheduled = (new DateTimeImmutable('@' . $letter['due_at']))->setTimezone(new DateTimeZone($letter['delivery_timezone']));
                $status = $letter['submitted_at'] !== null ? 'Submitted to mail' : ($letter['attempts'] >= 5 ? 'Needs attention' : 'Pending'); ?>
                <article class="letter">
                    <h3><?= escape($letter['subject']) ?></h3>
                    <p><?= escape($status) ?> · Scheduled for <?= escape($scheduled->format('Y-m-d H:i') . ' ' . $letter['delivery_timezone']) ?></p>
                    <details>
                        <summary>Read letter</summary>
                        <div class="letter-body"><?= escape($letter['body']) ?></div>
                    </details>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
