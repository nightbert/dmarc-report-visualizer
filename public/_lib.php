<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';
require_once __DIR__ . '/../report_index.php';

$APP_REPO_URL = 'https://github.com/nightbert/dmarc-report-visualizer';
$APP_VERSION = 'v1.3.0';
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

// Every stored report, ignoring the active filters. The masthead shows this on
// each page, so the figure has to mean the same thing everywhere.
function reportTotalCount(): int
{
    $root = resolveReportsRoot();
    if ($root === '') {
        return 0;
    }

    $db = reportIndexOpen($root);
    if ($db !== null) {
        try {
            // A zero means the index exists but has not been synced yet — this
            // is the one read path that never triggers a sync of its own, so
            // fall through to the files, which are the source of truth anyway.
            $count = reportIndexCount($db);
            if ($count > 0) {
                return $count;
            }
        } catch (Throwable $e) {
            error_log('report count query failed, falling back to file scan: ' . $e->getMessage());
        }
    }

    return count(listReportFiles($root));
}

// One filtered, paginated page of report summaries (index or scan). The listing
// shares the range, domain and org filters with the rest of the dashboard, so
// the whole page describes one slice of time.
function reportSummariesPage(int $page, int $perPage, string $range = '30d', string $org = '', string $domain = '', string $sort = 'start', string $dir = 'desc'): array
{
    $page = max(1, $page);
    $perPage = max(1, min(200, $perPage));
    $sort = array_key_exists($sort, reportIndexSortColumns()) ? $sort : 'start';
    $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
    $root = resolveReportsRoot();

    $db = $root !== '' ? reportIndexOpen($root) : null;
    if ($db !== null) {
        try {

            reportIndexSyncThrottled($db, $root, 'reportIndexParseFile', 60);
            $options = reportIndexFilterOptions($db);
            $window = reportRangeWindow($db, $range);
            $filters = [
                'start_ts' => $window['start_ts'],
                'end_ts' => $window['end_ts'],
                'org' => $org,
                'domain' => $domain,
            ];
            $result = reportIndexQueryPage($db, $filters, $perPage, ($page - 1) * $perPage, $sort, $dir);

            $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $summaries = [];
            $tokenIndex = [];
            foreach ($result['rows'] as $row) {
                $summary = reportIndexRowToSummary($row, $rootPrefix);
                $summary['token'] = buildFileToken($root, $summary['path']);
                if ($summary['token'] !== '') {
                    $tokenIndex[basename($summary['path'])] = $summary['token'];
                }
                $summaries[] = $summary;
            }

            return [
                'root' => $root,
                'summaries' => $summaries,
                'total' => (int)$result['total'],
                'total_all' => reportIndexCount($db),
                'page' => $page,
                'per_page' => $perPage,
                'sort' => $sort,
                'dir' => $dir,
                'range' => $window['range'],
                'range_label' => reportRangeLabel($window['range']),
                'window' => $window,
                'org_options' => $options['orgs'],
                'domain_options' => $options['domains'],
                'token_index' => $tokenIndex,
            ];
        } catch (Throwable $e) {
            error_log('report page query failed, falling back to file scan: ' . $e->getMessage());
        }
    }

    return reportSummariesPageFromScan($root, $page, $perPage, $range, $org, $domain, $sort, $dir);
}

// Sort key of a summary for one of the sortable listing columns. Null means the
// report has no value for it, which the ordering treats as empty — a numeric 0
// is a value, exactly as in the SQL path, where only NULL and '' sort last.
function reportSummarySortValue(array $summary, string $sort)
{
    switch ($sort) {
        case 'end':
            return ($summary['end_ts'] ?? null) !== null ? (int)$summary['end_ts'] : null;
        case 'org':
            return (string)($summary['org'] ?? '');
        case 'domain':
            return (string)($summary['domain'] ?? '');
        case 'report_id':
            return (string)($summary['report_id'] ?? '');
        case 'records':
            return (int)($summary['records'] ?? 0);
        case 'start':
        default:
            // Mirrors COALESCE(begin_ts, sort_ts) in the index query.
            return ($summary['begin_ts'] ?? null) !== null
                ? (int)$summary['begin_ts']
                : (int)($summary['timestamp'] ?? 0);
    }
}

// Order summaries like the SQL index does: empty values last, newest first as
// the tie-breaker.
function sortReportSummaries(array $summaries, string $sort, string $dir): array
{
    $descending = strtolower($dir) !== 'asc';
    usort($summaries, static function (array $a, array $b) use ($sort, $descending): int {
        $left = reportSummarySortValue($a, $sort);
        $right = reportSummarySortValue($b, $sort);
        $leftEmpty = $left === null || $left === '';
        $rightEmpty = $right === null || $right === '';
        if ($leftEmpty || $rightEmpty) {
            if ($leftEmpty && $rightEmpty) {
                return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
            }
            return $leftEmpty ? 1 : -1;
        }

        $result = is_string($left) ? strcasecmp($left, (string)$right) : ($left <=> $right);
        if ($result === 0) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        }
        return $descending ? -$result : $result;
    });

    return $summaries;
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

// Build a summaries page by scanning the files (index fallback). It has to
// agree with the index path above, filter for filter — a mismatch only shows on
// hosts without pdo_sqlite, where nothing would flag it.
function reportSummariesPageFromScan(string $root, int $page, int $perPage, string $range, string $org, string $domain, string $sort = 'start', string $dir = 'desc'): array
{
    $summaries = [];
    $orgs = [];
    $domains = [];
    $latest = 0;

    foreach (listReportFiles($root) as $file) {
        $summary = parseReportSummary($file);
        $summary['token'] = buildFileToken($root, $summary['path']);
        if ($summary['org'] !== '') {
            $orgs[$summary['org']] = true;
        }
        if ($summary['domain'] !== '') {
            $domains[$summary['domain']] = true;
        }
        $latest = max($latest, (int)$summary['timestamp']);
        $summaries[] = $summary;
    }

    $window = reportRangeWindowFromLatest($range, $latest);
    $filtered = array_values(array_filter($summaries, static function (array $s) use ($window, $org, $domain): bool {
        $ts = (int)($s['timestamp'] ?? 0);
        if ($window['start_ts'] > 0 && ($ts < $window['start_ts'] || $ts > $window['end_ts'])) {
            return false;
        }
        if ($org !== '' && ($s['org'] ?? '') !== $org) {
            return false;
        }
        if ($domain !== '' && ($s['domain'] ?? '') !== $domain) {
            return false;
        }
        return true;
    }));

    $filtered = sortReportSummaries($filtered, $sort, $dir);

    $total = count($filtered);
    $pageRows = array_slice($filtered, ($page - 1) * $perPage, $perPage);
    $tokenIndex = [];
    foreach ($pageRows as $summary) {
        if (($summary['token'] ?? '') !== '') {
            $tokenIndex[basename($summary['path'])] = $summary['token'];
        }
    }

    $orgOptions = array_keys($orgs);
    $domainOptions = array_keys($domains);
    usort($orgOptions, static function (string $a, string $b): int {
        return strcasecmp($a, $b);
    });
    usort($domainOptions, static function (string $a, string $b): int {
        return strcasecmp($a, $b);
    });

    return [
        'root' => $root,
        'summaries' => $pageRows,
        'total' => $total,
        'total_all' => count($summaries),
        'page' => $page,
        'per_page' => $perPage,
        'sort' => $sort,
        'dir' => $dir,
        'range' => $window['range'],
        'range_label' => reportRangeLabel($window['range']),
        'window' => $window,
        'org_options' => $orgOptions,
        'domain_options' => $domainOptions,
        'token_index' => $tokenIndex,
    ];
}

// Aggregate trend data for the trends view, or unavailable. Every figure is
// compared against the window immediately before the selected range.
function reportTrendsData(string $range = '30d', string $org = '', string $domain = '', int $topLimit = 25): array
{
    $root = resolveReportsRoot();
    $db = $root !== '' ? reportIndexOpen($root) : null;
    if ($db === null) {
        return ['available' => false];
    }

    try {
        reportIndexSyncThrottled($db, $root, 'reportIndexParseFile', 60);
        $options = reportIndexFilterOptions($db);
        $window = reportRangeWindow($db, $range);
        $filters = reportRecordFilters($window, $org, $domain);

        // A window with no records is not a comparison, so drop the deltas
        // rather than showing a change against nothing.
        $previous = null;
        if ($window['days'] > 0) {
            $previous = reportTrendsSummary($db, reportRecordFilters($window, $org, $domain, '', true));
            if ($previous['total'] === 0) {
                $previous = null;
            }
        }

        return [
            'available' => true,
            'range' => $window['range'],
            'range_label' => reportRangeLabel($window['range']),
            'window' => $window,
            'summary' => reportTrendsSummary($db, $filters),
            'previous' => $previous,
            'dispositions' => reportTrendsDispositions($db, $filters),
            'timeseries' => reportTrendsTimeseries($db, $filters),
            'top_senders' => reportTrendsTopSenders($db, $filters, $topLimit),
            'org_options' => $options['orgs'],
            'domain_options' => $options['domains'],
        ];
    } catch (Throwable $e) {
        error_log('trends query failed: ' . $e->getMessage());
        return ['available' => false];
    }
}

// Format a signed change for a delta chip ("+1.2", "-340", "0").
function formatDelta(float $value, int $decimals = 0): string
{
    $rounded = round($value, $decimals);
    $sign = $rounded > 0 ? '+' : ($rounded < 0 ? '-' : '');
    return $sign . number_format(abs($rounded), $decimals);
}

// Whether a delta reads as an improvement, a regression or no change.
function deltaClass(float $value, bool $riseIsGood): string
{
    if (round($value, 1) == 0.0) {
        return 'is-flat';
    }
    return (($value > 0) === $riseIsGood) ? 'is-good' : 'is-bad';
}

// Colour class for a pass rate, matching the trends stat cards.
function passRateClass(float $rate): string
{
    if ($rate >= 98.0) {
        return 'is-good';
    }
    return $rate >= 90.0 ? 'is-mid' : 'is-warn';
}

// Dashboard health panel: window totals compared against the preceding window,
// per-domain rows and the worst failing sources.
function reportHealthData(string $range = '30d', string $org = '', string $domain = '', int $domainLimit = 10, int $sourceLimit = 8): array
{
    $root = resolveReportsRoot();
    $db = $root !== '' ? reportIndexOpen($root) : null;
    if ($db === null) {
        return ['available' => false];
    }

    try {
        $window = reportRangeWindow($db, $range);
        if ($window['end_ts'] <= 0 && $window['days'] > 0) {
            return ['available' => false];
        }

        $filters = reportRecordFilters($window, $org, $domain);
        // "All time" resolves to an open window with no end, so the new-source
        // count anchors at the newest indexed day instead — otherwise the tile
        // would report zero on the one range that covers every source.
        $newSourceEnd = $window['end_ts'] > 0 ? (int)$window['end_ts'] : reportRecordsLatestDay($db);
        $newSourceDays = $window['days'] > 0 ? min(7, $window['days']) : 7;
        $newSourceStart = $newSourceEnd > 0 ? $newSourceEnd - $newSourceDays * 86400 + 1 : 0;
        $previous = $window['days'] > 0
            ? reportHealthWindow($db, reportRecordFilters($window, $org, $domain, '', true))
            : ['total' => 0, 'pass' => 0, 'fail' => 0, 'pass_rate' => 0.0, 'sources' => 0, 'domains' => 0, 'failing_sources' => 0];

        return [
            'available' => true,
            'range' => $window['range'],
            'range_label' => reportRangeLabel($window['range']),
            'window' => $window,
            'start_ts' => $window['start_ts'],
            'end_ts' => $window['end_ts'],
            'has_previous' => $previous['total'] > 0,
            'current' => reportHealthWindow($db, $filters),
            'previous' => $previous,
            'new_source_days' => $newSourceDays,
            'new_source_start_ts' => $newSourceStart,
            'new_sources' => $newSourceStart > 0 ? reportHealthNewSourceCount($db, $filters, $newSourceStart, $newSourceEnd) : 0,
            'domains' => reportHealthDomains($db, $filters, $domainLimit),
            'failing_sources' => reportHealthFailingSources($db, $filters, $sourceLimit),
        ];
    } catch (Throwable $e) {
        error_log('health query failed: ' . $e->getMessage());
        return ['available' => false];
    }
}

// Aggregate data for a single sender (source IP) drilldown.
function reportSenderData(string $ip, string $range = '30d', string $org = '', string $domain = ''): array
{
    $root = resolveReportsRoot();
    $db = $root !== '' ? reportIndexOpen($root) : null;
    if ($db === null) {
        return ['available' => false];
    }

    try {
        reportIndexSyncThrottled($db, $root, 'reportIndexParseFile', 60);
        $window = reportRangeWindow($db, $range);
        $filters = reportRecordFilters($window, $org, $domain, $ip);

        $reports = reportSenderReports($db, $filters);
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
            'range' => $window['range'],
            'range_label' => reportRangeLabel($window['range']),
            'summary' => reportTrendsSummary($db, $filters),
            'timeseries' => reportTrendsTimeseries($db, $filters),
            'by_domain' => reportSenderByDomain($db, $filters),
            'reports' => $reports,
        ];
    } catch (Throwable $e) {
        error_log('sender query failed: ' . $e->getMessage());
        return ['available' => false];
    }
}

// Reports behind one column of the alignment chart, or unavailable.
function reportBucketData(string $bucket, string $alignment, string $range = '30d', string $org = '', string $domain = '', string $ip = ''): array
{
    if (!preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $bucket)) {
        return ['available' => false];
    }

    $root = resolveReportsRoot();
    $db = $root !== '' ? reportIndexOpen($root) : null;
    if ($db === null) {
        return ['available' => false];
    }

    try {
        $window = reportRangeWindow($db, $range);
        $reports = reportBucketReports($db, $bucket, $alignment, reportRecordFilters($window, $org, $domain, $ip));
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $messages = 0;
        foreach ($reports as &$report) {
            $report['token'] = buildFileToken($root, $rootPrefix . $report['path']);
            $messages += (int)$report['total'];
        }
        unset($report);

        return [
            'available' => true,
            'bucket' => $bucket,
            'alignment' => $alignment,
            'messages' => $messages,
            'reports' => $reports,
        ];
    } catch (Throwable $e) {
        error_log('bucket query failed: ' . $e->getMessage());
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
