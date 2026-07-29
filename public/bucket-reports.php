<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=UTF-8');

$bucket = isset($_GET['bucket']) && is_string($_GET['bucket']) ? trim($_GET['bucket']) : '';
$alignment = isset($_GET['alignment']) && is_string($_GET['alignment']) ? trim($_GET['alignment']) : '';
$range = isset($_GET['range']) && is_string($_GET['range']) ? trim($_GET['range']) : '30d';
$org = isset($_GET['org']) && is_string($_GET['org']) ? trim($_GET['org']) : '';
$domain = isset($_GET['domain']) && is_string($_GET['domain']) ? trim($_GET['domain']) : '';
$ip = isset($_GET['ip']) && is_string($_GET['ip']) ? trim($_GET['ip']) : '';

if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
    $ip = '';
}

$data = reportBucketData($bucket, $alignment, $range, $org, $domain, $ip);

echo json_encode([
    'available' => (bool)($data['available'] ?? false),
    'bucket' => (string)($data['bucket'] ?? $bucket),
    'alignment' => (string)($data['alignment'] ?? $alignment),
    'messages' => (int)($data['messages'] ?? 0),
    'reports' => $data['reports'] ?? [],
], JSON_UNESCAPED_SLASHES);
