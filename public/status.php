<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';

$statusFile = resolveDataPath('STATUS_FILE', '/data/status.json', 'status.json');

header('Content-Type: application/json; charset=UTF-8');

$content = @file_get_contents($statusFile);
if ($content === false) {
    echo json_encode(['items' => [], 'updated_at' => 0, 'sequence' => 0]);
    exit;
}

$data = json_decode($content, true);
if (!is_array($data)) {
    echo json_encode(['items' => [], 'updated_at' => 0, 'sequence' => 0]);
    exit;
}

$items = is_array($data['items'] ?? null) ? $data['items'] : [];
$updatedAt = (int)($data['updated_at'] ?? 0);
$sequence = (int)($data['sequence'] ?? 0);

echo json_encode([
    'items' => $items,
    'updated_at' => max(0, $updatedAt),
    'sequence' => max(0, $sequence),
]);
