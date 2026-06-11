<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=UTF-8');

$year = isset($_GET['year']) && is_string($_GET['year']) ? trim($_GET['year']) : '';
$month = isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : '';
$org = isset($_GET['org']) && is_string($_GET['org']) ? trim($_GET['org']) : '';

$data = reportTrendsData($year, $month, $org);

echo json_encode([
    'available' => (bool)($data['available'] ?? false),
    'summary' => $data['summary'] ?? null,
    'timeseries' => $data['timeseries'] ?? [],
    'top_senders' => $data['top_senders'] ?? [],
    'year_options' => $data['year_options'] ?? [],
    'month_options' => $data['month_options'] ?? [],
    'org_options' => $data['org_options'] ?? [],
], JSON_UNESCAPED_SLASHES);
