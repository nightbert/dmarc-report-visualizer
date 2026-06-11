<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';
require_once __DIR__ . '/../public/_lib.php';
require_once __DIR__ . '/fetch-lib.php';

$tenantId = trim((string)getenv('M365_TENANT_ID'));
$clientId = trim((string)getenv('M365_CLIENT_ID'));
$clientSecret = trim((string)getenv('M365_CLIENT_SECRET'));
$mailbox = trim((string)getenv('M365_MAILBOX'));

if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $mailbox === '') {
    if ($tenantId !== '' || $clientId !== '' || $clientSecret !== '' || $mailbox !== '') {
        fetchLog('M365 fetching is partially configured; set M365_TENANT_ID, M365_CLIENT_ID, M365_CLIENT_SECRET, and M365_MAILBOX.');
    }
    exit(0);
}

$folderName = trim((string)getenv('M365_FOLDER'));
if ($folderName === '') {
    $folderName = 'Inbox';
}
$deleteAfterFetch = envFlag('M365_DELETE_AFTER_FETCH');
$maxMessages = (int)(getenv('M365_MAX_MESSAGES') ?: 25);
$maxMessages = max(1, min(100, $maxMessages));

$inboxDir = resolveDataPath('INBOX_DIR', '/data/inbox', 'inbox');
$statusFile = resolveDataPath('STATUS_FILE', '/data/status.json', 'status.json');
$stateFile = mailboxStateFile();
$statusName = 'M365: ' . $mailbox;

$lockHandle = beginFetchRun($inboxDir, $stateFile);

$token = graphAcquireToken($tenantId, $clientId, $clientSecret);
if ($token === '') {
    updateStatus($statusFile, $statusName, 'error', 100, 'M365 authentication failed (check tenant/client credentials).');
    writeFetchState($stateFile, 'error', 'Authentication failed.');
    exit(1);
}

$folderId = graphResolveFolderId($token, $mailbox, $folderName);
if ($folderId === '') {
    updateStatus($statusFile, $statusName, 'error', 100, "M365 folder not found: {$folderName}");
    writeFetchState($stateFile, 'error', "Folder not found: {$folderName}");
    exit(1);
}

$filter = 'hasAttachments eq true';
if (!$deleteAfterFetch) {
    $filter .= ' and isRead eq false';
}

$nextUrl = graphUrl('/users/' . rawurlencode($mailbox) . '/mailFolders/' . rawurlencode($folderId) . '/messages')
    . '?%24select=id,subject,receivedDateTime'
    . '&%24top=' . $maxMessages
    . '&%24filter=' . rawurlencode($filter);

$saved = 0;
$failed = 0;
$page = 0;

while ($nextUrl !== '') {
    [$status, $body] = graphRequest('GET', $nextUrl, $token);
    if ($status !== 200) {
        updateStatus($statusFile, $statusName, 'error', 100, 'M365 message listing failed (HTTP ' . $status . ').');
        fetchLog("Message listing failed: HTTP {$status} {$body}");
        writeFetchState($stateFile, 'error', 'Message listing failed (HTTP ' . $status . ').');
        exit(1);
    }

    $decoded = json_decode($body, true);
    $messages = is_array($decoded) ? ($decoded['value'] ?? []) : [];
    $nextUrl = is_array($decoded) ? (string)($decoded['@odata.nextLink'] ?? '') : '';

    if (!is_array($messages) || empty($messages)) {
        if ($page === 0) {
            writeFetchState($stateFile, 'ok', 'No new messages.');
            exit(0);
        }
        break;
    }

    $page++;
    updateStatus($statusFile, $statusName, 'processing', 20, 'Fetching messages from mailbox (page ' . $page . ')…');

    foreach ($messages as $message) {
        $messageId = (string)($message['id'] ?? '');
        $subject = (string)($message['subject'] ?? '');
        if ($messageId === '') {
            continue;
        }

        $mimeUrl = graphUrl('/users/' . rawurlencode($mailbox) . '/messages/' . rawurlencode($messageId) . '/%24value');
        [$mimeStatus, $mime] = graphRequest('GET', $mimeUrl, $token);
        if ($mimeStatus !== 200 || $mime === '') {
            $failed++;
            fetchLog("MIME download failed (HTTP {$mimeStatus}) for message: {$subject}");
            continue;
        }

        $receivedTs = strtotime((string)($message['receivedDateTime'] ?? '')) ?: null;
        if (!storeEmlInInbox($inboxDir, $mime, $receivedTs, 'm365')) {
            $failed++;
            fetchLog("Could not store EML for message: {$subject}");
            continue;
        }
        $saved++;

        if ($deleteAfterFetch) {
            $deleteUrl = graphUrl('/users/' . rawurlencode($mailbox) . '/messages/' . rawurlencode($messageId));
            [$deleteStatus] = graphRequest('DELETE', $deleteUrl, $token);
            if ($deleteStatus !== 204) {
                fetchLog("Could not delete message (HTTP {$deleteStatus}): {$subject}");
            }
        } else {
            $patchUrl = graphUrl('/users/' . rawurlencode($mailbox) . '/messages/' . rawurlencode($messageId));
            [$patchStatus] = graphRequest('PATCH', $patchUrl, $token, json_encode(['isRead' => true]), 'application/json');
            if ($patchStatus !== 200) {
                fetchLog("Could not mark message as read (HTTP {$patchStatus}): {$subject}");
            }
        }
    }
}

finishFetch($statusFile, $statusName, $stateFile, $saved, $failed);

// Build a Microsoft Graph API URL.
function graphUrl(string $path): string
{
    return 'https://graph.microsoft.com/v1.0' . $path;
}

// Obtain a Graph access token via client credentials.
function graphAcquireToken(string $tenantId, string $clientId, string $clientSecret): string
{
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $body = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ]);

    [$status, $response] = httpRequest('POST', $url, [
        'Content-Type: application/x-www-form-urlencoded',
    ], $body);

    if ($status !== 200) {
        fetchLog("Token request failed: HTTP {$status} {$response}");
        return '';
    }

    $decoded = json_decode($response, true);
    $token = is_array($decoded) ? (string)($decoded['access_token'] ?? '') : '';
    if ($token === '') {
        fetchLog('Token response contained no access_token.');
    }
    return $token;
}

// Resolve a mail folder name to its Graph id.
function graphResolveFolderId(string $token, string $mailbox, string $folderName): string
{
    $wellKnown = ['inbox', 'archive', 'junkemail', 'deleteditems', 'drafts', 'sentitems'];
    $normalized = strtolower($folderName);
    if (in_array($normalized, $wellKnown, true)) {
        return $normalized;
    }

    $escaped = str_replace("'", "''", $folderName);
    $url = graphUrl('/users/' . rawurlencode($mailbox) . '/mailFolders')
        . '?%24select=id,displayName'
        . '&%24filter=' . rawurlencode("displayName eq '{$escaped}'");

    [$status, $body] = graphRequest('GET', $url, $token);
    if ($status !== 200) {
        fetchLog("Folder lookup failed: HTTP {$status} {$body}");
        return '';
    }

    $decoded = json_decode($body, true);
    $folders = is_array($decoded) ? ($decoded['value'] ?? []) : [];
    foreach ($folders as $folder) {
        $id = (string)($folder['id'] ?? '');
        if ($id !== '') {
            return $id;
        }
    }
    return '';
}

// Perform an authenticated Graph API request.
function graphRequest(string $method, string $url, string $token, ?string $body = null, string $contentType = ''): array
{
    $headers = ['Authorization: Bearer ' . $token];
    if ($contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    return httpRequest($method, $url, $headers, $body);
}

// Perform an HTTP request (curl with a stream fallback).
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            fetchLog("HTTP request failed: {$error}");
            return [0, ''];
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string)$response];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 120,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
            $status = (int)$m[1];
        }
    }
    return [$status, is_string($response) ? $response : ''];
}
