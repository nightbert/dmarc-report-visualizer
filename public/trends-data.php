<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=UTF-8');

$range = isset($_GET['range']) && is_string($_GET['range']) ? trim($_GET['range']) : '30d';
$org = isset($_GET['org']) && is_string($_GET['org']) ? trim($_GET['org']) : '';
$domain = isset($_GET['domain']) && is_string($_GET['domain']) ? trim($_GET['domain']) : '';

$data = reportTrendsData($range, $org, $domain);

echo json_encode([
    'available' => (bool)($data['available'] ?? false),
    'range' => $data['range'] ?? $range,
    'range_label' => $data['range_label'] ?? '',
    'window' => $data['window'] ?? [],
    'summary' => $data['summary'] ?? null,
    'previous' => $data['previous'] ?? null,
    'dispositions' => $data['dispositions'] ?? [],
    'timeseries' => $data['timeseries'] ?? [],
    'top_senders' => $data['top_senders'] ?? [],
    'org_options' => $data['org_options'] ?? [],
    'domain_options' => $data['domain_options'] ?? [],
], JSON_UNESCAPED_SLASHES);
