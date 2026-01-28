<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';

$APP_REPO_URL = 'https://github.com/nightbert/dmarc-report-visualizer';
$APP_VERSION = 'v1.0.0';

function preferredReportsDir(): string
{
    $envValue = getenv('REPORTS_DIR');
    if ($envValue !== false && $envValue !== '') {
        return $envValue;
    }

    return '/data/reports';
}

function reportRootCandidates(): array
{
    $candidates = [
        preferredReportsDir(),
        repoDataPath('reports'),
    ];
    $filtered = array_filter($candidates, static function (string $path): bool {
        return $path !== '';
    });
    return array_values(array_unique($filtered));
}

function detectReportLocation(): array
{
    $candidates = reportRootCandidates();
    $root = '';
    foreach ($candidates as $candidate) {
        if ($candidate === '' || !is_dir($candidate)) {
            continue;
        }
        $files = listReportFiles($candidate);
        if (!empty($files)) {
            $root = $candidate;
            break;
        }
    }

    $files = [];
    if ($root === '') {
        $root = $candidates[0] ?? '';
    }
    if ($root !== '' && is_dir($root)) {
        $files = listReportFiles($root);
    }
    return ['root' => $root, 'files' => $files];
}

function reportsRoot(): string
{
    $location = detectReportLocation();
    return $location['root'] ?? '';
}

function getReportFiles(): array
{
    $location = detectReportLocation();
    return $location['files'] ?? [];
}

function normalizePath(string $path): string
{
    $real = realpath($path);
    return $real !== false ? $real : $path;
}

function listReportFiles(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        if (strtolower($fileInfo->getExtension()) !== 'xml') {
            continue;
        }
        $files[] = $fileInfo->getPathname();
    }

    return $files;
}

function parseReportSummary(string $path): array
{
    $summary = [
        'path' => $path,
        'timestamp' => filemtime($path) ?: 0,
        'org' => 'Unknown',
        'report_id' => '',
        'domain' => '',
        'records' => 0,
        'date_range' => '',
    ];

    $content = @file_get_contents($path);
    if ($content === false) {
        return $summary;
    }

    $xml = loadXml($content);
    if ($xml === null) {
        return $summary;
    }

    $summary['org'] = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="org_name"]') ?: 'Unknown';
    $summary['report_id'] = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="report_id"]');
    $summary['domain'] = xmlValue($xml, '//*[local-name()="policy_published"]/*[local-name()="domain"]');

    $begin = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="begin"]');
    $end = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="end"]');
    if (ctype_digit($begin)) {
        $summary['timestamp'] = (int)$begin;
    }

    if (ctype_digit($begin) && ctype_digit($end)) {
        $summary['date_range'] = date('Y-m-d', (int)$begin) . ' - ' . date('Y-m-d', (int)$end);
    }

    $records = $xml->xpath('//*[local-name()="record"]');
    $summary['records'] = is_array($records) ? count($records) : 0;

    return $summary;
}

function loadXml(string $content): ?SimpleXMLElement
{
    $content = preg_replace('/^\\xEF\\xBB\\xBF/', '', $content);
    $content = preg_replace('/[^\\x09\\x0A\\x0D\\x20-\\x7E\\x80-\\xFF]/', '', $content);

    if ($content === null || $content === '') {
        return null;
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $xml instanceof SimpleXMLElement ? $xml : null;
}

function xmlValue(SimpleXMLElement $context, string $path): string
{
    $nodes = $context->xpath($path);
    if (!is_array($nodes) || !isset($nodes[0])) {
        return '';
    }

    return trim((string)$nodes[0]);
}

function xmlValues(SimpleXMLElement $context, string $path): array
{
    $nodes = $context->xpath($path);
    if (!is_array($nodes) || $nodes === []) {
        return [];
    }

    $values = [];
    foreach ($nodes as $node) {
        $value = trim((string)$node);
        if ($value !== '') {
            $values[] = $value;
        }
    }

    return $values;
}

function xmlFirstValue(SimpleXMLElement $context, array $paths): string
{
    foreach ($paths as $path) {
        $value = xmlValue($context, $path);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function buildFileToken(string $root, string $path): string
{
    $root = rtrim(normalizePath($root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $normalized = normalizePath($path);
    if (strpos($normalized, $root) !== 0) {
        return '';
    }
    $relative = substr($normalized, strlen($root));
    return base64_encode($relative);
}

function appRepoUrl(): string
{
    global $APP_REPO_URL;
    return rtrim((string)$APP_REPO_URL, '/');
}

function appVersion(): string
{
    global $APP_VERSION;
    return (string)$APP_VERSION;
}

function appReleaseUrl(string $repoUrl, string $version): string
{
    if ($repoUrl === '' || $version === '') {
        return '';
    }

    return rtrim($repoUrl, '/') . '/releases/tag/' . rawurlencode($version);
}

function resolveFileToken(string $root, string $token): ?string
{
    $root = rtrim(normalizePath($root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $relative = base64_decode($token, true);
    if ($relative === false || $relative === '' || str_contains($relative, '..')) {
        return null;
    }

    $candidate = $root . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative);
    $candidate = normalizePath($candidate);
    if (strpos($candidate, $root) !== 0 || !is_file($candidate)) {
        return null;
    }

    return $candidate;
}

function reportRetentionMonths(): int
{
    $envValue = getenv('REPORT_RETENTION_MONTHS');
    if ($envValue !== false && $envValue !== '' && ctype_digit($envValue)) {
        return max(0, (int)$envValue);
    }

    return 0;
}

function purgeOldReports(string $root, int $months = 6): int
{
    if ($months <= 0 || $root === '' || !is_dir($root)) {
        return 0;
    }

    $cutoff = (new DateTimeImmutable('now'))->modify('-' . $months . ' months')->getTimestamp();
    $deleted = 0;

    foreach (listReportFiles($root) as $path) {
        $summary = parseReportSummary($path);
        $timestamp = (int)($summary['timestamp'] ?? 0);
        if ($timestamp === 0 || $timestamp >= $cutoff) {
            continue;
        }
        if (@unlink($path)) {
            $deleted++;
            removeEmptyParents($root, $path);
        }
    }

    return $deleted;
}

function removeEmptyParents(string $root, string $path): void
{
    $root = rtrim(normalizePath($root), DIRECTORY_SEPARATOR);
    $current = dirname(normalizePath($path));

    while ($current !== '' && $current !== $root && strpos($current, $root) === 0) {
        $entries = @scandir($current);
        if ($entries === false) {
            break;
        }
        $entries = array_diff($entries, ['.', '..']);
        if (!empty($entries)) {
            break;
        }
        @rmdir($current);
        $current = dirname($current);
    }
}
