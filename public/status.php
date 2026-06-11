<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';
require_once __DIR__ . '/../bin/fetch-lib.php';

$statusFile = resolveDataPath('STATUS_FILE', '/data/status.json', 'status.json');
$inboxDir = resolveDataPath('INBOX_DIR', '/data/inbox', 'inbox');
$stateFile = mailboxStateFile();

header('Content-Type: application/json; charset=UTF-8');

$items = [];
$updatedAt = 0;
$sequence = 0;

$content = @file_get_contents($statusFile);
if ($content !== false) {
    $data = json_decode($content, true);
    if (is_array($data)) {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $updatedAt = (int)($data['updated_at'] ?? 0);
        $sequence = (int)($data['sequence'] ?? 0);
    }
}

$knownNames = [];
foreach ($items as $item) {
    $knownNames[(string)($item['name'] ?? '')] = true;
}

$pending = [];
$entries = @scandir($inboxDir);
if (is_array($entries)) {
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $inboxDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path) || isset($knownNames[$entry])) {
            continue;
        }
        $pending[] = [
            'name' => $entry,
            'stage' => 'pending',
            'progress' => 0,
            'message' => 'Waiting in inbox.',
            'updated_at' => @filemtime($path) ?: time(),
            'sequence' => 0,
        ];
    }
}

$provider = mailboxProvider();
$mailbox = [
    'provider' => $provider,
    'configured' => $provider !== '',
    'last_fetch_at' => 0,
    'result' => '',
    'message' => '',
];
$stateRaw = @file_get_contents($stateFile);
if ($stateRaw !== false) {
    $state = json_decode($stateRaw, true);
    if (is_array($state)) {
        $mailbox['last_fetch_at'] = max(0, (int)($state['last_fetch_at'] ?? 0));
        $mailbox['result'] = (string)($state['result'] ?? '');
        $mailbox['message'] = (string)($state['message'] ?? '');
    }
}

echo json_encode([
    'items' => array_merge($pending, $items),
    'updated_at' => max(0, $updatedAt),
    'sequence' => max(0, $sequence),
    'mailbox' => $mailbox,
]);
