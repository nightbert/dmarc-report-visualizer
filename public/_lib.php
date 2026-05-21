<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';

$APP_REPO_URL = 'https://github.com/nightbert/dmarc-report-visualizer';
$APP_VERSION = 'v1.1.1';

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

function reportSummariesData(): array
{
    $location = detectReportLocation();
    $root = $location['root'] ?? '';
    $files = $location['files'] ?? [];
    $summaries = [];
    $years = [];
    $months = [];
    $orgs = [];
    $tokenIndex = [];

    foreach ($files as $file) {
        $summary = parseReportSummary($file);
        $summary['token'] = buildFileToken($root, $file);
        $summary['year'] = $summary['timestamp'] ? date('Y', $summary['timestamp']) : '';
        $summary['month'] = $summary['timestamp'] ? date('m', $summary['timestamp']) : '';
        $summaries[] = $summary;
        if ($summary['token'] !== '') {
            $tokenIndex[basename($summary['path'])] = $summary['token'];
        }

        if ($summary['year'] !== '') {
            $years[$summary['year']] = true;
        }
        if ($summary['month'] !== '') {
            $months[$summary['month']] = true;
        }
        if ($summary['org'] !== '') {
            $orgs[$summary['org']] = true;
        }
    }

    usort($summaries, function (array $a, array $b): int {
        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });

    $yearOptions = array_keys($years);
    $monthOptions = array_keys($months);
    $orgOptions = array_keys($orgs);
    sort($yearOptions);
    sort($monthOptions);
    usort($orgOptions, static function (string $a, string $b): int {
        return strcasecmp($a, $b);
    });

    return [
        'root' => $root,
        'summaries' => $summaries,
        'total' => count($summaries),
        'year_options' => $yearOptions,
        'month_options' => $monthOptions,
        'org_options' => $orgOptions,
        'token_index' => $tokenIndex,
    ];
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

    $summary['report_id'] = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="report_id"]');
    $summary['domain'] = dmarcReportDomain($xml, $summary['report_id']);
    $summary['org'] = dmarcReportOrg($xml, $summary['domain']);

    $begin = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="begin"]');
    $end = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="end"]');
    $beginTimestamp = parseDmarcTimestamp($begin);
    $endTimestamp = parseDmarcTimestamp($end);
    if ($beginTimestamp !== null) {
        $summary['timestamp'] = $beginTimestamp;
    }

    if ($beginTimestamp !== null && $endTimestamp !== null) {
        $summary['date_range'] = date('Y-m-d', $beginTimestamp) . ' - ' . date('Y-m-d', $endTimestamp);
    }

    $records = $xml->xpath('//*[local-name()="record"]');
    $summary['records'] = is_array($records) ? count($records) : 0;

    return $summary;
}

function parseDmarcTimestamp(string $value): ?int
{
    $value = trim($value);
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }

    $timestamp = (int)$value;
    if (strlen(ltrim($value, '0')) >= 13) {
        $timestamp = intdiv($timestamp, 1000);
    }

    return $timestamp > 0 ? $timestamp : null;
}

function dmarcFingerprintTimestamp(string $value): string
{
    $timestamp = parseDmarcTimestamp($value);
    return $timestamp !== null ? (string)$timestamp : trim($value);
}

function dmarcReportDomain(SimpleXMLElement $xml, string $reportId = ''): string
{
    $domain = xmlValue($xml, '//*[local-name()="policy_published"]/*[local-name()="domain"]');
    if ($domain !== '') {
        $domain = normalizeDmarcDomain($domain);
        if ($domain !== '') {
            return $domain;
        }
    }

    $domain = dmarcReportIdDomain($reportId);
    if ($domain !== '') {
        return $domain;
    }

    return dmarcMetadataEmailDomain($xml);
}

function dmarcReportOrg(SimpleXMLElement $xml, string $fallbackDomain = ''): string
{
    $org = normalizeDmarcOrgName(xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="org_name"]'));
    if ($org !== '') {
        return $org;
    }

    return dmarcMetadataEmailDomain($xml) ?: $fallbackDomain ?: 'Unknown';
}

function normalizeDmarcOrgName(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return preg_match('/^[\\s.\\-_]+$/', $value) === 1 ? '' : $value;
}

function dmarcReportIdDomain(string $reportId): string
{
    $reportId = trim($reportId);
    if ($reportId === '') {
        return '';
    }

    $parts = preg_split('/[:\\s]+/', $reportId);
    if (!is_array($parts)) {
        return '';
    }

    foreach ($parts as $part) {
        $domain = normalizeDmarcDomain($part);
        if ($domain !== '') {
            return $domain;
        }
    }

    return '';
}

function dmarcMetadataEmailDomain(SimpleXMLElement $xml): string
{
    $email = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="email"]');
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    return normalizeDmarcDomain(substr(strrchr($email, '@'), 1));
}

function normalizeDmarcDomain(string $value): string
{
    $value = strtolower(trim($value));
    $value = trim($value, " \t\n\r\0\x0B<>[]().,;:'\"");
    $value = rtrim($value, '.');

    if ($value === '' || !str_contains($value, '.') || str_contains($value, '@')) {
        return '';
    }

    return preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $value) === 1 ? $value : '';
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
