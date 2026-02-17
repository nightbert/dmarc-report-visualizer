<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=UTF-8');

$data = reportSummariesData();

echo json_encode([
    'total' => (int)($data['total'] ?? 0),
    'summaries' => $data['summaries'] ?? [],
    'year_options' => $data['year_options'] ?? [],
    'month_options' => $data['month_options'] ?? [],
    'org_options' => $data['org_options'] ?? [],
    'token_index' => $data['token_index'] ?? [],
], JSON_UNESCAPED_SLASHES);
