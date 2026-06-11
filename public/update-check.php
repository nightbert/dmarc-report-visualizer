<?php

declare(strict_types=1);

require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=UTF-8');

const UPDATE_CHECK_TTL = 21600;

$current = appVersion();
$repoUrl = appRepoUrl();
$cacheFile = resolveDataPath('UPDATE_CHECK_FILE', '/data/update-check.json', 'update-check.json');

// Emit the update-check JSON response.
function updateCheckRespond(string $current, string $latest, string $releaseUrl, int $checkedAt): void
{
    echo json_encode([
        'current' => $current,
        'latest' => $latest,
        'release_url' => $releaseUrl,
        'update_available' => $latest !== '' && isNewerVersion($latest, $current),
        'checked_at' => $checkedAt,
    ], JSON_UNESCAPED_SLASHES);
}

$cached = null;
$cacheRaw = @file_get_contents($cacheFile);
if ($cacheRaw !== false) {
    $decoded = json_decode($cacheRaw, true);
    if (is_array($decoded)) {
        $cached = $decoded;
    }
}

if (is_array($cached) && (time() - (int)($cached['checked_at'] ?? 0)) < UPDATE_CHECK_TTL) {
    updateCheckRespond(
        $current,
        (string)($cached['latest'] ?? ''),
        (string)($cached['release_url'] ?? ''),
        (int)($cached['checked_at'] ?? 0)
    );
    exit;
}

$apiUrl = appReleasesApiUrl($repoUrl);
$latest = '';
$releaseUrl = '';

if ($apiUrl !== '') {
    [$status, $body] = updateCheckHttpGet($apiUrl);
    if ($status === 200) {
        $payload = json_decode($body, true);
        if (is_array($payload)) {
            $latest = (string)($payload['tag_name'] ?? '');
            $releaseUrl = (string)($payload['html_url'] ?? '');
        }
    }
}

if ($latest !== '') {
    $checkedAt = time();
    @file_put_contents($cacheFile, json_encode([
        'latest' => $latest,
        'release_url' => $releaseUrl,
        'checked_at' => $checkedAt,
    ], JSON_UNESCAPED_SLASHES));
    updateCheckRespond($current, $latest, $releaseUrl, $checkedAt);
    exit;
}

if (is_array($cached)) {
    updateCheckRespond(
        $current,
        (string)($cached['latest'] ?? ''),
        (string)($cached['release_url'] ?? ''),
        (int)($cached['checked_at'] ?? 0)
    );
    exit;
}

updateCheckRespond($current, '', '', 0);

// HTTP GET the GitHub API (curl with a stream fallback).
function updateCheckHttpGet(string $url): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: dmarc-report-visualizer',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return [0, ''];
        }
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$statusCode, (string)$response];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $statusCode = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
            $statusCode = (int)$m[1];
        }
    }
    return [$statusCode, is_string($response) ? $response : ''];
}
