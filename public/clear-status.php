<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';

$statusFile = resolveDataPath('STATUS_FILE', '/data/status.json', 'status.json');

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = ['items' => [], 'updated_at' => time()];
$dir = dirname($statusFile);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

$fp = @fopen($statusFile, 'c+');
if ($fp === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to write status']);
    exit;
}

flock($fp, LOCK_EX);
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true]);
