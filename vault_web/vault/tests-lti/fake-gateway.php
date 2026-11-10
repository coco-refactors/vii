<?php

/**
 * Test-only gateway. Records hooks in FAKE_GW_DIR and returns
 * the HTTP status stored in that directory's "mode" file.
 */
$dir = getenv('FAKE_GW_DIR');

if (!$dir || !is_dir($dir)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Test gateway directory is unavailable.']);
    return;
}

$body = file_get_contents('php://input');
$record = [
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'timestamp' => $_SERVER['HTTP_X_VII_TIMESTAMP'] ?? null,
    'signature' => $_SERVER['HTTP_X_VII_SIGNATURE'] ?? null,
    'body' => $body,
];

$file = $dir . '/req-' . sprintf('%020d', hrtime(true))
    . '-' . mt_rand() . '.json';

file_put_contents($file, json_encode($record));

$mode = (int)trim(@file_get_contents($dir . '/mode') ?: '200');

http_response_code($mode);
header('Content-Type: application/json');
echo json_encode(['ok' => $mode >= 200 && $mode < 300]);