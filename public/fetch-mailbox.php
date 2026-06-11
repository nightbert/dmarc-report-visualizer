<?php

declare(strict_types=1);

require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/../bin/fetch-lib.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$provider = mailboxProvider();
if ($provider === '') {
    http_response_code(409);
    echo json_encode(['error' => 'No mailbox is configured']);
    exit;
}

if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
    http_response_code(500);
    echo json_encode(['error' => 'exec() is not available on this runtime']);
    exit;
}

$fetchScript = realpath(__DIR__ . '/../bin/fetch-mail.php');
$ingestScript = realpath(__DIR__ . '/../bin/ingest.php');
if ($fetchScript === false || $ingestScript === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Fetch script not found']);
    exit;
}

$statusFile = resolveDataPath('STATUS_FILE', '/data/status.json', 'status.json');
updateStatus($statusFile, mailboxStatusName($provider), 'queued', 5, 'Mailbox fetch requested.');

$cmd = sprintf(
    '(php %s; php %s) > /dev/null 2>&1 &',
    escapeshellarg($fetchScript),
    escapeshellarg($ingestScript)
);
exec($cmd);

echo json_encode(['started' => true]);
