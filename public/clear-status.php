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

$payload = [];
$rawPayload = @file_get_contents('php://input');
if (is_string($rawPayload) && trim($rawPayload) !== '') {
    $decoded = json_decode($rawPayload, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
$mode = strtolower(trim((string)($payload['mode'] ?? 'completed')));
if ($mode !== 'all' && $mode !== 'completed') {
    $mode = 'completed';
}
$terminalStages = ['done', 'error', 'ignored', 'duplicate'];

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
rewind($fp);
$raw = stream_get_contents($fp);

$status = ['items' => [], 'updated_at' => 0, 'sequence' => 0];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $status = array_merge($status, $decoded);
    }
}

$items = is_array($status['items'] ?? null) ? $status['items'] : [];
$removed = 0;
if ($mode === 'completed') {
    $items = array_values(array_filter($items, static function ($item) use ($terminalStages, &$removed): bool {
        if (!is_array($item)) {
            $removed++;
            return false;
        }
        $stage = strtolower(trim((string)($item['stage'] ?? '')));
        if ($stage !== '' && in_array($stage, $terminalStages, true)) {
            $removed++;
            return false;
        }
        return true;
    }));
} else {
    $removed = count($items);
    $items = [];
}

$status['items'] = $items;
$status['updated_at'] = time();
$status['sequence'] = max(0, (int)($status['sequence'] ?? 0)) + 1;

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($status, JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true, 'removed' => $removed, 'mode' => $mode, 'sequence' => $status['sequence']]);
