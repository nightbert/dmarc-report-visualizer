<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';
require_once __DIR__ . '/../report_index.php';

$APP_REPO_URL = 'https://github.com/nightbert/dmarc-report-visualizer';
$APP_VERSION = 'v1.2.0';
$APP_AUTHOR = 'nightbert';

// Configured reports directory (env override or default).
function preferredReportsDir(): string
{
    $envValue = getenv('REPORTS_DIR');
    if ($envValue !== false && $envValue !== '') {
        return $envValue;
    }

    return '/data/reports';
}

// Candidate reports directories to probe, in order.
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

// The active reports root directory.
function reportsRoot(): string
{
    return resolveReportsRoot();
}

// Pick the first candidate reports dir that holds XML files.
function resolveReportsRoot(): string
{
    $candidates = reportRootCandidates();
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_dir($candidate) && reportIndexDirHasXml($candidate)) {
            return $candidate;
        }
    }
    return $candidates[0] ?? '';
}

// One filtered, paginated page of report summaries (index or scan).
function reportSummariesPage(int $page, int $perPage, ?string $year = null, ?string $month = null, ?string $org = null): array
{
    $page = max(1, $page);
    $perPage = max(1, min(200, $perPage));
    $root = resolveReportsRoot();

    $db = $root !== '' ? reportIndexOpen($root) : null;
    if ($db !== null) {
        try {

            reportIndexSyncThrottled($db, $root, 'reportIndexParseFile', 60);
            $options = reportIndexFilterOptions($db);
            $result = reportIndexQueryPage($db, $year, $month, $org, $perPage, ($page - 1) * $perPage);

            $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $summaries = [];
            $tokenIndex = [];
            foreach ($result['rows'] as $row) {
                $summary = reportIndexRowToSummary($row, $rootPrefix);
                $summary['token'] = buildFileToken($root, $summary['path']);
                $summary['year'] = $summary['timestamp'] ? date('Y', $summary['timestamp']) : '';
                $summary['month'] = $summary['timestamp'] ? date('m', $summary['timestamp']) : '';
                if ($summary['token'] !== '') {
                    $tokenIndex[basename($summary['path'])] = $summary['token'];
                }
                $summaries[] = $summary;
            }

            return [
                'root' => $root,
                'summaries' => $summaries,
                'total' => (int)$result['total'],
                'page' => $page,
                'per_page' => $perPage,
                'year_options' => $options['years'],
                'month_options' => $options['months'],
                'org_options' => $options['orgs'],
                'token_index' => $tokenIndex,
            ];
        } catch (Throwable $e) {
            error_log('report page query failed, falling back to file scan: ' . $e->getMessage());
        }
    }

    return reportSummariesPageFromScan($root, $page, $perPage, $year, $month, $org);
}

// Convert an index row into a report summary array.
function reportIndexRowToSummary(array $row, string $rootPrefix): array
{
    $beginTs = $row['begin_ts'] !== null ? (int)$row['begin_ts'] : null;
    $endTs = $row['end_ts'] !== null ? (int)$row['end_ts'] : null;
    $dateRange = '';
    if ($beginTs !== null && $endTs !== null) {
        $dateRange = date('Y-m-d', $beginTs) . ' - ' . date('Y-m-d', $endTs);
    }

    return [
        'path' => $rootPrefix . (string)$row['path'],
        'timestamp' => (int)$row['sort_ts'],
        'org' => (string)$row['org'],
        'report_id' => (string)$row['report_id'],
        'domain' => (string)$row['domain'],
        'records' => (int)$row['records'],
        'date_range' => $dateRange,
        'begin_ts' => $beginTs,
        'end_ts' => $endTs,
    ];
}

// Build a summaries page by scanning the files (index fallback).
function reportSummariesPageFromScan(string $root, int $page, int $perPage, ?string $year, ?string $month, ?string $org): array
{
    $summaries = [];
    $years = [];
    $months = [];
    $orgs = [];

    foreach (listReportFiles($root) as $file) {
        $summary = parseReportSummary($file);
        $summary['token'] = buildFileToken($root, $summary['path']);
        $summary['year'] = $summary['timestamp'] ? date('Y', $summary['timestamp']) : '';
        $summary['month'] = $summary['timestamp'] ? date('m', $summary['timestamp']) : '';
        if ($summary['year'] !== '') {
            $years[$summary['year']] = true;
        }
        if ($summary['month'] !== '') {
            $months[$summary['month']] = true;
        }
        if ($summary['org'] !== '') {
            $orgs[$summary['org']] = true;
        }
        $summaries[] = $summary;
    }

    $filtered = array_values(array_filter($summaries, static function (array $s) use ($year, $month, $org): bool {
        if ($year !== null && $year !== '' && ($s['year'] ?? '') !== $year) {
            return false;
        }
        if ($month !== null && $month !== '' && ($s['month'] ?? '') !== $month) {
            return false;
        }
        if ($org !== null && $org !== '' && ($s['org'] ?? '') !== $org) {
            return false;
        }
        return true;
    }));

    usort($filtered, static function (array $a, array $b): int {
        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });

    $total = count($filtered);
    $pageRows = array_slice($filtered, ($page - 1) * $perPage, $perPage);
    $tokenIndex = [];
    foreach ($pageRows as $summary) {
        if (($summary['token'] ?? '') !== '') {
            $tokenIndex[basename($summary['path'])] = $summary['token'];
        }
    }

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
        'summaries' => $pageRows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'year_options' => $yearOptions,
        'month_options' => $monthOptions,
        'org_options' => $orgOptions,
        'token_index' => $tokenIndex,
    ];
}

// Aggregate trend data for the trends view, or unavailable.
function reportTrendsData(?string $year = null, ?string $month = null, ?string $org = null, int $topLimit = 25): array
{
    $root = resolveReportsRoot();
    $db = $root !== '' ? reportIndexOpen($root) : null;
    if ($db === null) {
        return ['available' => false];
    }

    try {
        reportIndexSyncThrottled($db, $root, 'reportIndexParseFile', 60);
        $options = reportIndexFilterOptions($db);

        return [
            'available' => true,
            'summary' => reportTrendsSummary($db, $year, $month, $org),
            'timeseries' => reportTrendsTimeseries($db, $year, $month, $org),
            'top_senders' => reportTrendsTopSenders($db, $year, $month, $org, $topLimit),
            'year_options' => $options['years'],
            'month_options' => $options['months'],
            'org_options' => $options['orgs'],
        ];
    } catch (Throwable $e) {
        error_log('trends query failed: ' . $e->getMessage());
        return ['available' => false];
    }
}

// Aggregate data for a single sender (source IP) drilldown.
function reportSenderData(string $ip, ?string $year = null, ?string $month = null, ?string $org = null): array
{
    $root = resolveReportsRoot();
    $db = $root !== '' ? reportIndexOpen($root) : null;
    if ($db === null) {
        return ['available' => false];
    }

    try {
        reportIndexSyncThrottled($db, $root, 'reportIndexParseFile', 60);

        $reports = reportSenderReports($db, $ip, $year, $month, $org);
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($reports as &$report) {
            $report['token'] = buildFileToken($root, $rootPrefix . $report['path']);
            $report['date_range'] = ($report['begin_ts'] !== null && $report['end_ts'] !== null)
                ? date('Y-m-d', $report['begin_ts']) . ' - ' . date('Y-m-d', $report['end_ts'])
                : '';
        }
        unset($report);

        return [
            'available' => true,
            'ip' => $ip,
            'summary' => reportTrendsSummary($db, $year, $month, $org, $ip),
            'timeseries' => reportTrendsTimeseries($db, $year, $month, $org, $ip),
            'by_domain' => reportSenderByDomain($db, $ip, $year, $month, $org),
            'reports' => $reports,
        ];
    } catch (Throwable $e) {
        error_log('sender query failed: ' . $e->getMessage());
        return ['available' => false];
    }
}

// Parse a report file once into summary, fingerprint and record details.
function reportIndexParseFile(string $path): array
{

    $fallbackTs = filemtime($path) ?: 0;
    $content = @file_get_contents($path);
    $xml = ($content !== false && $content !== '') ? loadXml($content) : null;

    if ($xml instanceof SimpleXMLElement) {
        $summary = parseReportSummaryFromXml($xml, $path, $fallbackTs);
        $fingerprint = reportFingerprintFromXml($xml);
        $recordsDetail = parseReportRecords($xml);
    } else {
        $summary = parseReportSummaryDefault($path, $fallbackTs);
        $fingerprint = '';
        $recordsDetail = [];
    }

    return [
        'fingerprint' => $fingerprint,
        'org' => (string)($summary['org'] ?? ''),
        'report_id' => (string)($summary['report_id'] ?? ''),
        'domain' => (string)($summary['domain'] ?? ''),
        'records' => (int)($summary['records'] ?? 0),
        'begin_ts' => $summary['begin_ts'] ?? null,
        'end_ts' => $summary['end_ts'] ?? null,
        'timestamp' => (int)($summary['timestamp'] ?? 0),
        'records_detail' => $recordsDetail,
    ];
}

// Extract per-record (source IP) detail rows from a report.
function parseReportRecords(SimpleXMLElement $xml): array
{
    $nodes = $xml->xpath('//*[local-name()="record"]');
    if (!is_array($nodes)) {
        return [];
    }

    $records = [];
    foreach ($nodes as $record) {
        $sourceIp = xmlValue($record, './*[local-name()="row"]/*[local-name()="source_ip"]');
        $countRaw = xmlValue($record, './*[local-name()="row"]/*[local-name()="count"]');
        $disposition = xmlValue($record, './*[local-name()="row"]/*[local-name()="policy_evaluated"]/*[local-name()="disposition"]');
        $dkim = xmlValue($record, './*[local-name()="row"]/*[local-name()="policy_evaluated"]/*[local-name()="dkim"]');
        $spf = xmlValue($record, './*[local-name()="row"]/*[local-name()="policy_evaluated"]/*[local-name()="spf"]');
        $headerFrom = xmlValue($record, './*[local-name()="identifiers"]/*[local-name()="header_from"]');

        $records[] = [
            'source_ip' => $sourceIp,
            'count' => ctype_digit($countRaw) ? (int)$countRaw : 0,
            'disposition' => $disposition,
            'dkim_aligned' => strtolower($dkim) === 'pass',
            'spf_aligned' => strtolower($spf) === 'pass',
            'header_from' => strtolower($headerFrom),
        ];
    }

    return $records;
}

// Compute a report's dedup fingerprint from a file.
function reportFingerprintFromXmlFile(string $xmlPath): string
{
    $content = @file_get_contents($xmlPath);
    if ($content === false || $content === '') {
        return '';
    }
    $xml = loadXml($content);
    if (!$xml instanceof SimpleXMLElement) {
        return '';
    }

    return reportFingerprintFromXml($xml);
}

// Compute a report's dedup fingerprint from parsed XML.
function reportFingerprintFromXml(SimpleXMLElement $xml): string
{
    $reportId = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="report_id"]');
    $domain = dmarcReportDomain($xml, $reportId);
    $begin = dmarcFingerprintTimestamp(xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="begin"]'));
    $end = dmarcFingerprintTimestamp(xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="end"]'));

    if ($reportId === '' || $domain === '' || $begin === '' || $end === '') {
        return '';
    }

    return strtolower($reportId . '|' . $domain . '|' . $begin . '|' . $end);
}

// Resolve a path to its real path, or return it unchanged.
function normalizePath(string $path): string
{
    $real = realpath($path);
    return $real !== false ? $real : $path;
}

// All XML report files under a root directory.
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

// Default/empty summary for an unparseable report.
function parseReportSummaryDefault(string $path, int $fallbackTs): array
{
    return [
        'path' => $path,
        'timestamp' => $fallbackTs,
        'org' => 'Unknown',
        'report_id' => '',
        'domain' => '',
        'records' => 0,
        'date_range' => '',
        'begin_ts' => null,
        'end_ts' => null,
    ];
}

// Parse a report file into a summary, with fallbacks.
function parseReportSummary(string $path): array
{
    $fallbackTs = filemtime($path) ?: 0;
    $content = @file_get_contents($path);
    if ($content === false) {
        return parseReportSummaryDefault($path, $fallbackTs);
    }

    $xml = loadXml($content);
    if ($xml === null) {
        return parseReportSummaryDefault($path, $fallbackTs);
    }

    return parseReportSummaryFromXml($xml, $path, $fallbackTs);
}

// Build a report summary from parsed XML.
function parseReportSummaryFromXml(SimpleXMLElement $xml, string $path, int $fallbackTs): array
{
    $summary = parseReportSummaryDefault($path, $fallbackTs);

    $summary['report_id'] = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="report_id"]');
    $summary['domain'] = dmarcReportDomain($xml, $summary['report_id']);
    $summary['org'] = dmarcReportOrg($xml, $summary['domain']);

    $begin = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="begin"]');
    $end = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="end"]');
    $beginTimestamp = parseDmarcTimestamp($begin);
    $endTimestamp = parseDmarcTimestamp($end);
    $summary['begin_ts'] = $beginTimestamp;
    $summary['end_ts'] = $endTimestamp;
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

// Parse a DMARC epoch value (seconds or millis) to a timestamp.
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

// Normalized timestamp string used in the fingerprint.
function dmarcFingerprintTimestamp(string $value): string
{
    $timestamp = parseDmarcTimestamp($value);
    return $timestamp !== null ? (string)$timestamp : trim($value);
}

// Resolve the report's published policy domain.
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

// Resolve the reporting organization name.
function dmarcReportOrg(SimpleXMLElement $xml, string $fallbackDomain = ''): string
{
    $org = normalizeDmarcOrgName(xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="org_name"]'));
    if ($org !== '') {
        return $org;
    }

    return dmarcMetadataEmailDomain($xml) ?: $fallbackDomain ?: 'Unknown';
}

// Clean an org name, dropping placeholder-only values.
function normalizeDmarcOrgName(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return preg_match('/^[\\s.\\-_]+$/', $value) === 1 ? '' : $value;
}

// Derive a domain from the report id.
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

// Domain taken from the report metadata email address.
function dmarcMetadataEmailDomain(SimpleXMLElement $xml): string
{
    $email = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="email"]');
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    return normalizeDmarcDomain(substr(strrchr($email, '@'), 1));
}

// Normalize and validate a domain string.
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

// Parse a string into SimpleXML, sanitizing it first, or null.
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

// First trimmed text value at an XPath, or empty.
function xmlValue(SimpleXMLElement $context, string $path): string
{
    $nodes = $context->xpath($path);
    if (!is_array($nodes) || !isset($nodes[0])) {
        return '';
    }

    return trim((string)$nodes[0]);
}

// All non-empty trimmed text values at an XPath.
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

// First non-empty value across several XPaths.
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

// Encode a report file path into an opaque URL token.
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

// Configured GitHub repository URL.
function appRepoUrl(): string
{
    global $APP_REPO_URL;
    return rtrim((string)$APP_REPO_URL, '/');
}

// Configured application version.
function appVersion(): string
{
    global $APP_VERSION;
    return (string)$APP_VERSION;
}

// Configured application author shown in the footer.
function appAuthor(): string
{
    global $APP_AUTHOR;
    return (string)$APP_AUTHOR;
}

// URL of the GitHub release for a version.
function appReleaseUrl(string $repoUrl, string $version): string
{
    if ($repoUrl === '' || $version === '') {
        return '';
    }

    return rtrim($repoUrl, '/') . '/releases/tag/' . rawurlencode($version);
}

// GitHub API URL for the latest release.
function appReleasesApiUrl(string $repoUrl): string
{
    if ($repoUrl === '') {
        return '';
    }

    $path = parse_url(rtrim($repoUrl, '/'), PHP_URL_PATH);
    $slug = trim((string)$path, '/');
    if ($slug === '' || substr_count($slug, '/') !== 1) {
        return '';
    }

    return 'https://api.github.com/repos/' . $slug . '/releases/latest';
}

// Parse a version string into [major, minor, patch].
function parseVersion(string $version): ?array
{
    if (!preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $m)) {
        return null;
    }

    return [
        (int)$m[1],
        (int)($m[2] ?? 0),
        (int)($m[3] ?? 0),
    ];
}

// Whether one version is newer than another.
function isNewerVersion(string $latest, string $current): bool
{
    $a = parseVersion($latest);
    $b = parseVersion($current);
    if ($a === null || $b === null) {
        return false;
    }

    return $a > $b;
}

// Resolve a URL token back to a safe report file path, or null.
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

// Configured report retention in months (0 = keep forever).
function reportRetentionMonths(): int
{
    $envValue = getenv('REPORT_RETENTION_MONTHS');
    if ($envValue !== false && $envValue !== '' && ctype_digit($envValue)) {
        return max(0, (int)$envValue);
    }

    return 0;
}

// Delete reports older than the retention window.
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

// Remove now-empty parent directories up to the root.
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

// Write or update an entry in the live status feed file.
function updateStatus(string $statusFile, string $name, string $stage, int $progress, string $message): void
{
    $dir = dirname($statusFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $fp = @fopen($statusFile, 'c+');
    if ($fp === false) {
        return;
    }

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $status = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $status = $decoded;
        }
    }

    $now = time();
    $sequence = max(0, (int)($status['sequence'] ?? 0)) + 1;
    $entry = [
        'name' => $name,
        'stage' => $stage,
        'progress' => max(0, min(100, $progress)),
        'message' => $message,
        'updated_at' => $now,
        'sequence' => $sequence,
    ];

    $items = $status['items'] ?? [];
    $found = false;
    foreach ($items as $idx => $item) {
        if (($item['name'] ?? '') === $name) {
            $items[$idx] = $entry;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $items[] = $entry;
    }

    $status['items'] = pruneStatusItems($items);
    $status['updated_at'] = $now;
    $status['sequence'] = $sequence;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($status, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Drop status entries older than a day and cap the list.
function pruneStatusItems(array $items): array
{
    $cutoff = time() - 86400;
    $filtered = [];

    foreach ($items as $item) {
        $updated = (int)($item['updated_at'] ?? 0);
        if ($updated < $cutoff) {
            continue;
        }
        $filtered[] = $item;
    }

    usort($filtered, function (array $a, array $b): int {
        return ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0);
    });

    return array_slice($filtered, 0, 50);
}
