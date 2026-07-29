<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=UTF-8');

$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$range = isset($_GET['range']) && is_string($_GET['range']) ? trim($_GET['range']) : '30d';
$org = isset($_GET['org']) && is_string($_GET['org']) ? trim($_GET['org']) : '';
$domain = isset($_GET['domain']) && is_string($_GET['domain']) ? trim($_GET['domain']) : '';
$sort = isset($_GET['sort']) && is_string($_GET['sort']) ? trim($_GET['sort']) : 'start';
$dir = isset($_GET['dir']) && is_string($_GET['dir']) ? trim($_GET['dir']) : 'desc';

$data = reportSummariesPage($page, $perPage, $range, $org, $domain, $sort, $dir);

echo json_encode([
    'total' => (int)($data['total'] ?? 0),
    'total_all' => (int)($data['total_all'] ?? 0),
    'page' => (int)($data['page'] ?? 1),
    'per_page' => (int)($data['per_page'] ?? $perPage),
    'sort' => (string)($data['sort'] ?? 'start'),
    'dir' => (string)($data['dir'] ?? 'desc'),
    'range' => (string)($data['range'] ?? $range),
    'summaries' => $data['summaries'] ?? [],
    'org_options' => $data['org_options'] ?? [],
    'domain_options' => $data['domain_options'] ?? [],
    'token_index' => $data['token_index'] ?? [],
], JSON_UNESCAPED_SLASHES);
