<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$root = reportsRoot();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$token = '';
if (!empty($_POST['token']) && is_string($_POST['token'])) {
    $token = $_POST['token'];
} else {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    $token = is_array($payload) ? ($payload['token'] ?? '') : '';
    $token = is_string($token) ? $token : '';
}

if ($token === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token']);
    exit;
}

$filePath = resolveFileToken($root, $token);
if ($filePath === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Report not found']);
    exit;
}

if (!@unlink($filePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Delete failed']);
    exit;
}

removeEmptyParents($root, $filePath);

echo json_encode(['ok' => true]);
