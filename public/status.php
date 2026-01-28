<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';

$statusFile = preferredDataPath('STATUS_FILE', '/data/status.json');

header('Content-Type: application/json; charset=UTF-8');

$content = @file_get_contents($statusFile);
if ($content === false) {
    echo json_encode(['items' => [], 'updated_at' => 0]);
    exit;
}

$data = json_decode($content, true);
if (!is_array($data)) {
    echo json_encode(['items' => [], 'updated_at' => 0]);
    exit;
}

echo json_encode($data);

function preferredDataPath(string $envKey, string $default): string
{
    $envValue = getenv($envKey);
    if ($envValue !== false && $envValue !== '') {
        return $envValue;
    }

    return $default;
}
