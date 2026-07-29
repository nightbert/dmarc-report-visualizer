<?php

declare(strict_types=1);

require_once __DIR__ . '/data_paths.php';

const REPORT_INDEX_DATA_VERSION = 2;

// Whether the pdo_sqlite extension is available for the index.
function reportIndexAvailable(): bool
{
    return extension_loaded('pdo_sqlite');
}

// Path to the SQLite index file (env override or default in the reports dir).
function reportIndexFile(string $reportsDir): string
{
    $envValue = getenv('REPORT_INDEX_FILE');
    if ($envValue !== false && $envValue !== '') {
        return $envValue;
    }
    return rtrim($reportsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.report-index.sqlite';
}

// Open the SQLite index, creating its schema, or null if unavailable.
function reportIndexOpen(string $reportsDir): ?PDO
{
    if (!reportIndexAvailable() || $reportsDir === '') {
        return null;
    }

    $file = reportIndexFile($reportsDir);
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }

    try {
        $db = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('CREATE TABLE IF NOT EXISTS reports (
            path TEXT PRIMARY KEY,
            mtime INTEGER NOT NULL,
            size INTEGER NOT NULL,
            fingerprint TEXT NOT NULL DEFAULT \'\',
            org TEXT NOT NULL DEFAULT \'\',
            report_id TEXT NOT NULL DEFAULT \'\',
            domain TEXT NOT NULL DEFAULT \'\',
            records INTEGER NOT NULL DEFAULT 0,
            begin_ts INTEGER,
            end_ts INTEGER,
            sort_ts INTEGER NOT NULL DEFAULT 0
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_reports_fingerprint ON reports (fingerprint)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_reports_sort ON reports (sort_ts)');

        $db->exec('CREATE TABLE IF NOT EXISTS report_records (
            report_path TEXT NOT NULL,
            source_ip TEXT NOT NULL DEFAULT \'\',
            message_count INTEGER NOT NULL DEFAULT 0,
            disposition TEXT NOT NULL DEFAULT \'\',
            dkim_aligned INTEGER NOT NULL DEFAULT 0,
            spf_aligned INTEGER NOT NULL DEFAULT 0,
            header_from TEXT NOT NULL DEFAULT \'\',
            org TEXT NOT NULL DEFAULT \'\',
            domain TEXT NOT NULL DEFAULT \'\',
            day_ts INTEGER NOT NULL DEFAULT 0
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_records_path ON report_records (report_path)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_records_day ON report_records (day_ts)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_records_ip ON report_records (source_ip)');
        $db->exec('CREATE TABLE IF NOT EXISTS report_index_meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT \'\'
        )');
        reportIndexMigrate($db);

        @chmod($file, 0666);
        return $db;
    } catch (Throwable $e) {
        error_log('report index unavailable (' . $file . '): ' . $e->getMessage());
        return null;
    }
}

// Rebuild the index when its stored data version is outdated.
function reportIndexMigrate(PDO $db): void
{
    $stored = (int)(reportIndexGetMeta($db, 'data_version') ?? '0');
    if ($stored === REPORT_INDEX_DATA_VERSION) {
        return;
    }
    try {
        $db->exec('DELETE FROM reports');
        $db->exec('DELETE FROM report_records');

        reportIndexSetMeta($db, 'last_sync', '0');
        reportIndexSetMeta($db, 'data_version', (string)REPORT_INDEX_DATA_VERSION);
    } catch (Throwable $e) {
        error_log('report index migration failed: ' . $e->getMessage());
    }
}

// Path of a report file relative to the reports root.
function reportIndexRelativePath(string $reportsDir, string $path): string
{
    $rootReal = realpath($reportsDir);
    $pathReal = realpath($path);
    $root = $rootReal !== false ? $rootReal : rtrim($reportsDir, DIRECTORY_SEPARATOR);
    $abs = $pathReal !== false ? $pathReal : $path;

    $prefix = $root . DIRECTORY_SEPARATOR;
    if (str_starts_with($abs, $prefix)) {
        return substr($abs, strlen($prefix));
    }
    return $abs;
}

// Reconcile the index with the XML files on disk (add/update/remove).
function reportIndexSync(PDO $db, string $reportsDir, callable $parser): void
{
    $onDisk = [];
    if (is_dir($reportsDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($reportsDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'xml') {
                continue;
            }
            $abs = $fileInfo->getPathname();
            $onDisk[reportIndexRelativePath($reportsDir, $abs)] = [
                'abs' => $abs,
                'mtime' => $fileInfo->getMTime(),
                'size' => $fileInfo->getSize(),
            ];
        }
    }

    $known = [];
    foreach ($db->query('SELECT path, mtime, size FROM reports') as $row) {
        $known[(string)$row['path']] = $row;
    }

    $delete = $db->prepare('DELETE FROM reports WHERE path = ?');
    $deleteRecords = $db->prepare('DELETE FROM report_records WHERE report_path = ?');
    foreach ($known as $rel => $row) {
        if (!isset($onDisk[$rel])) {
            $delete->execute([$rel]);
            $deleteRecords->execute([$rel]);
        }
    }

    foreach ($onDisk as $rel => $info) {
        $row = $known[$rel] ?? null;
        if ($row !== null && (int)$row['mtime'] === $info['mtime'] && (int)$row['size'] === $info['size']) {
            continue;
        }
        $meta = $parser($info['abs']);
        if (!is_array($meta)) {
            continue;
        }
        reportIndexUpsert($db, $rel, $info['mtime'], $info['size'], $meta);
    }
}

// Insert or update a report's row and its records in the index.
function reportIndexUpsert(PDO $db, string $relativePath, int $mtime, int $size, array $meta): void
{
    $stmt = $db->prepare('INSERT INTO reports
        (path, mtime, size, fingerprint, org, report_id, domain, records, begin_ts, end_ts, sort_ts)
        VALUES (:path, :mtime, :size, :fingerprint, :org, :report_id, :domain, :records, :begin_ts, :end_ts, :sort_ts)
        ON CONFLICT(path) DO UPDATE SET
            mtime = excluded.mtime,
            size = excluded.size,
            fingerprint = excluded.fingerprint,
            org = excluded.org,
            report_id = excluded.report_id,
            domain = excluded.domain,
            records = excluded.records,
            begin_ts = excluded.begin_ts,
            end_ts = excluded.end_ts,
            sort_ts = excluded.sort_ts');
    $stmt->execute([
        ':path' => $relativePath,
        ':mtime' => $mtime,
        ':size' => $size,
        ':fingerprint' => (string)($meta['fingerprint'] ?? ''),
        ':org' => (string)($meta['org'] ?? ''),
        ':report_id' => (string)($meta['report_id'] ?? ''),
        ':domain' => (string)($meta['domain'] ?? ''),
        ':records' => (int)($meta['records'] ?? 0),
        ':begin_ts' => $meta['begin_ts'] ?? null,
        ':end_ts' => $meta['end_ts'] ?? null,
        ':sort_ts' => (int)($meta['timestamp'] ?? 0),
    ]);

    reportIndexReplaceRecords($db, $relativePath, $meta);
}

// Replace the per-record rows for a report in the index.
function reportIndexReplaceRecords(PDO $db, string $relativePath, array $meta): void
{
    $del = $db->prepare('DELETE FROM report_records WHERE report_path = ?');
    $del->execute([$relativePath]);

    $records = $meta['records_detail'] ?? null;
    if (!is_array($records) || $records === []) {
        return;
    }

    $org = (string)($meta['org'] ?? '');
    $domain = (string)($meta['domain'] ?? '');
    $dayTs = (int)($meta['begin_ts'] ?? $meta['timestamp'] ?? 0);

    $insert = $db->prepare('INSERT INTO report_records
        (report_path, source_ip, message_count, disposition, dkim_aligned, spf_aligned, header_from, org, domain, day_ts)
        VALUES (:path, :ip, :count, :disp, :dkim, :spf, :hfrom, :org, :domain, :day_ts)');
    foreach ($records as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $insert->execute([
            ':path' => $relativePath,
            ':ip' => (string)($rec['source_ip'] ?? ''),
            ':count' => (int)($rec['count'] ?? 0),
            ':disp' => (string)($rec['disposition'] ?? ''),
            ':dkim' => !empty($rec['dkim_aligned']) ? 1 : 0,
            ':spf' => !empty($rec['spf_aligned']) ? 1 : 0,
            ':hfrom' => (string)($rec['header_from'] ?? ''),
            ':org' => $org,
            ':domain' => $domain,
            ':day_ts' => $dayTs,
        ]);
    }
}

// Index a single report file by its absolute path.
function reportIndexInsertFile(PDO $db, string $reportsDir, string $absolutePath, array $meta): void
{
    $mtime = @filemtime($absolutePath);
    $size = @filesize($absolutePath);
    if ($mtime === false || $size === false) {
        return;
    }
    reportIndexUpsert($db, reportIndexRelativePath($reportsDir, $absolutePath), $mtime, $size, $meta);
}

// Whether a report fingerprint already exists in the index.
function reportIndexFingerprintKnown(PDO $db, string $fingerprint): bool
{
    if ($fingerprint === '') {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM reports WHERE fingerprint = ? LIMIT 1');
    $stmt->execute([$fingerprint]);
    return $stmt->fetchColumn() !== false;
}

// All known report fingerprints from the index.
function reportIndexAllFingerprints(PDO $db): array
{
    $fingerprints = [];
    foreach ($db->query("SELECT DISTINCT fingerprint FROM reports WHERE fingerprint <> ''") as $row) {
        $fingerprints[(string)$row['fingerprint']] = true;
    }
    return $fingerprints;
}

// How many reports the index holds, ignoring every filter.
function reportIndexCount(PDO $db): int
{
    return (int)$db->query('SELECT COUNT(*) FROM reports')->fetchColumn();
}

// Whether the reports table has no rows.
function reportIndexIsEmpty(PDO $db): bool
{
    return (int)$db->query('SELECT COUNT(*) FROM reports')->fetchColumn() === 0;
}

// Whether the reports directory contains any XML file.
function reportIndexDirHasXml(string $reportsDir): bool
{
    if (!is_dir($reportsDir)) {
        return false;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($reportsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'xml') {
            return true;
        }
    }
    return false;
}

// Build the WHERE clause and params for the report listing: the resolved
// window, the reporting org and the reported domain. Reports are placed by
// their sort timestamp, the same value the listing orders by.
function reportIndexFilterClause(array $filters): array
{
    $clauses = [];
    $params = [];
    if ((int)($filters['start_ts'] ?? 0) > 0) {
        $clauses[] = 'sort_ts >= :start_ts';
        $params[':start_ts'] = (int)$filters['start_ts'];
    }
    if ((int)($filters['end_ts'] ?? 0) > 0) {
        $clauses[] = 'sort_ts <= :end_ts';
        $params[':end_ts'] = (int)$filters['end_ts'];
    }
    if ((string)($filters['org'] ?? '') !== '') {
        $clauses[] = 'org = :org';
        $params[':org'] = (string)$filters['org'];
    }
    if ((string)($filters['domain'] ?? '') !== '') {
        $clauses[] = 'domain = :domain';
        $params[':domain'] = (string)$filters['domain'];
    }
    $where = $clauses === [] ? '' : (' WHERE ' . implode(' AND ', $clauses));
    return [$where, $params];
}

// Sortable report columns mapped to their ORDER BY expression. Unknown keys
// fall back to the report start date, which is the default listing order.
function reportIndexSortColumns(): array
{
    return [
        'start' => 'COALESCE(begin_ts, sort_ts)',
        'end' => 'end_ts',
        'org' => 'org COLLATE NOCASE',
        'domain' => 'domain COLLATE NOCASE',
        'report_id' => 'report_id COLLATE NOCASE',
        'records' => 'records',
    ];
}

// Fetch one filtered, paginated page of report rows plus the total.
function reportIndexQueryPage(PDO $db, array $filters, int $limit, int $offset, string $sort = 'start', string $dir = 'desc'): array
{
    [$where, $params] = reportIndexFilterClause($filters);

    $countStmt = $db->prepare('SELECT COUNT(*) FROM reports' . $where);
    reportBindParams($countStmt, $params);
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $columns = reportIndexSortColumns();
    $expression = $columns[$sort] ?? $columns['start'];
    $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
    // Keep rows without a value at the end in both directions.
    $order = "CASE WHEN {$expression} IS NULL OR {$expression} = '' THEN 1 ELSE 0 END, {$expression} {$direction}, sort_ts DESC";

    $sql = 'SELECT * FROM reports' . $where . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset';
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
}

// Distinct year, month, organization and domain values for the filters.
function reportIndexFilterOptions(PDO $db): array
{
    $years = [];
    foreach ($db->query("SELECT DISTINCT strftime('%Y', sort_ts, 'unixepoch') AS y FROM reports WHERE sort_ts > 0 ORDER BY y") as $row) {
        $value = (string)$row['y'];
        if ($value !== '') {
            $years[] = $value;
        }
    }

    $months = [];
    foreach ($db->query("SELECT DISTINCT strftime('%m', sort_ts, 'unixepoch') AS m FROM reports WHERE sort_ts > 0 ORDER BY m") as $row) {
        $value = (string)$row['m'];
        if ($value !== '') {
            $months[] = $value;
        }
    }

    $orgs = [];
    foreach ($db->query("SELECT DISTINCT org FROM reports WHERE org <> '' ORDER BY org COLLATE NOCASE") as $row) {
        $orgs[] = (string)$row['org'];
    }

    $domains = [];
    foreach ($db->query("SELECT DISTINCT domain FROM reports WHERE domain <> '' ORDER BY domain COLLATE NOCASE") as $row) {
        $domains[] = (string)$row['domain'];
    }

    return ['years' => $years, 'months' => $months, 'orgs' => $orgs, 'domains' => $domains];
}

// Read a value from the index metadata table.
function reportIndexGetMeta(PDO $db, string $key): ?string
{
    try {
        $stmt = $db->prepare('SELECT value FROM report_index_meta WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    } catch (Throwable $e) {
        return null;
    }
}

// Write a value to the index metadata table (best-effort).
function reportIndexSetMeta(PDO $db, string $key, string $value): void
{
    try {
        $stmt = $db->prepare('INSERT INTO report_index_meta (key, value) VALUES (:key, :value)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([':key' => $key, ':value' => $value]);
    } catch (Throwable $e) {

    }
}

// Run a sync only if the last one is older than the interval.
function reportIndexSyncThrottled(PDO $db, string $reportsDir, callable $parser, int $minIntervalSeconds): void
{
    $now = time();
    $last = (int)(reportIndexGetMeta($db, 'last_sync') ?? '0');
    if ($last > 0 && ($now - $last) < $minIntervalSeconds) {
        return;
    }
    reportIndexSync($db, $reportsDir, $parser);
    reportIndexSetMeta($db, 'last_sync', (string)$now);
}

// Remove a report and its records from the index.
function reportIndexDeletePath(PDO $db, string $reportsDir, string $absolutePath): void
{
    try {
        $rel = reportIndexRelativePath($reportsDir, $absolutePath);
        $db->prepare('DELETE FROM reports WHERE path = ?')->execute([$rel]);
        $db->prepare('DELETE FROM report_records WHERE report_path = ?')->execute([$rel]);
    } catch (Throwable $e) {
        error_log('report index delete failed: ' . $e->getMessage());
    }
}

// Selectable trend ranges mapped to their length in days (0 = every record).
function reportRangeOptions(): array
{
    return ['7d' => 7, '30d' => 30, '90d' => 90, '12m' => 365, 'all' => 0];
}

// Human label for a range key.
function reportRangeLabel(string $range): string
{
    $labels = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        '12m' => 'Last 12 months',
        'all' => 'All time',
    ];
    return $labels[$range] ?? $labels['30d'];
}

// Resolve a range key into its window plus the preceding window of equal
// length, so views can show a change. The window is anchored at the newest
// indexed day rather than today, because reports arrive days after the fact,
// and snapped to whole UTC days because day_ts holds a report's raw begin_ts.
function reportRangeWindow(PDO $db, string $range): array
{
    return reportRangeWindowFromLatest($range, reportRecordsLatestDay($db));
}

// The same resolution against an already known anchor, for the report listing's
// index-less scan fallback.
function reportRangeWindowFromLatest(string $range, int $latest): array
{
    $options = reportRangeOptions();
    $range = isset($options[$range]) ? $range : '30d';
    $days = $options[$range];

    if ($latest <= 0 || $days === 0) {
        return [
            'range' => $range,
            'days' => $days,
            'start_ts' => 0,
            'end_ts' => 0,
            'previous_start_ts' => 0,
            'previous_end_ts' => 0,
        ];
    }

    $day = 86400;
    $end = $latest - ($latest % $day) + $day - 1;
    $start = $end - $days * $day + 1;
    return [
        'range' => $range,
        'days' => $days,
        'start_ts' => $start,
        'end_ts' => $end,
        'previous_start_ts' => $start - $days * $day,
        'previous_end_ts' => $start - 1,
    ];
}

// Record-level filters for a resolved window plus the active org, domain and
// source ip. Pass $previous to target the comparison window instead.
function reportRecordFilters(array $window, string $org, string $domain, string $ip = '', bool $previous = false): array
{
    return [
        'start_ts' => $previous ? ($window['previous_start_ts'] ?? 0) : ($window['start_ts'] ?? 0),
        'end_ts' => $previous ? ($window['previous_end_ts'] ?? 0) : ($window['end_ts'] ?? 0),
        'days' => $window['days'] ?? 0,
        'org' => $org,
        'domain' => $domain,
        'ip' => $ip,
    ];
}

// Bind params with their SQL type: integers bound as text never match an
// aggregate like MIN(day_ts), which carries no column affinity.
function reportBindParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

// Build the WHERE clause and params for record-level queries. Filters carry the
// resolved window (start_ts/end_ts), the reporting org, the reported domain and
// a source ip; every key is optional.
function reportRecordsFilterClause(array $filters, string $alias = ''): array
{
    $col = $alias !== '' ? $alias . '.' : '';
    $clauses = [$col . 'day_ts > 0'];
    $params = [];
    if ((int)($filters['start_ts'] ?? 0) > 0) {
        $clauses[] = $col . 'day_ts >= :start_ts';
        $params[':start_ts'] = (int)$filters['start_ts'];
    }
    if ((int)($filters['end_ts'] ?? 0) > 0) {
        $clauses[] = $col . 'day_ts <= :end_ts';
        $params[':end_ts'] = (int)$filters['end_ts'];
    }
    if ((string)($filters['org'] ?? '') !== '') {
        $clauses[] = $col . 'org = :org';
        $params[':org'] = (string)$filters['org'];
    }
    if ((string)($filters['domain'] ?? '') !== '') {
        $clauses[] = $col . 'domain = :domain';
        $params[':domain'] = (string)$filters['domain'];
    }
    if ((string)($filters['ip'] ?? '') !== '') {
        $clauses[] = $col . 'source_ip = :ip';
        $params[':ip'] = (string)$filters['ip'];
    }
    return [' WHERE ' . implode(' AND ', $clauses), $params];
}

// Message volume split by DMARC alignment, bucketed per day for ranges up to a
// quarter and per month (most recent 12) beyond that.
function reportTrendsTimeseries(PDO $db, array $filters): array
{
    [$where, $params] = reportRecordsFilterClause($filters);

    // Daily buckets stay readable up to about a quarter (≤92 bars). Longer
    // ranges group by month and keep the most recent 12 buckets, so the
    // overview never shows one bar per day since the first report ingested.
    $days = (int)($filters['days'] ?? 0);
    $byMonth = $days === 0 || $days > 92;
    $bucket = $byMonth
        ? "strftime('%Y-%m', day_ts, 'unixepoch')"
        : "strftime('%Y-%m-%d', day_ts, 'unixepoch')";
    $sql = "SELECT $bucket AS day,
                SUM(message_count) AS total,
                SUM(CASE WHEN dkim_aligned = 1 AND spf_aligned = 1 THEN message_count ELSE 0 END) AS full_pass,
                SUM(CASE WHEN dkim_aligned = 1 AND spf_aligned = 0 THEN message_count ELSE 0 END) AS dkim_only,
                SUM(CASE WHEN dkim_aligned = 0 AND spf_aligned = 1 THEN message_count ELSE 0 END) AS spf_only,
                SUM(CASE WHEN dkim_aligned = 0 AND spf_aligned = 0 THEN message_count ELSE 0 END) AS fail
            FROM report_records" . $where . ' GROUP BY day';
    if ($byMonth) {
        // Most recent 12 months, then back to chronological order for the chart.
        $sql = "SELECT * FROM ($sql ORDER BY day DESC LIMIT 12) ORDER BY day ASC";
    } else {
        $sql .= ' ORDER BY day';
    }
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->execute();
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = [
            'day' => (string)$row['day'],
            'total' => (int)$row['total'],
            'full' => (int)$row['full_pass'],
            'dkim_only' => (int)$row['dkim_only'],
            'spf_only' => (int)$row['spf_only'],
            'fail' => (int)$row['fail'],
        ];
    }

    $rows = fillTimeseriesGaps($rows, $byMonth);
    if ($byMonth && count($rows) > 12) {
        // Keep the most recent 12 calendar months once gaps are filled.
        $rows = array_slice($rows, -12);
    }
    return $rows;
}

// Insert zero-value buckets for any calendar months (or days) missing between
// the first and last bucket, so the chart shows a continuous axis instead of
// collapsing gaps where no reports were ingested.
function fillTimeseriesGaps(array $rows, bool $byMonth): array
{
    if (count($rows) < 2) {
        return $rows;
    }

    $existing = [];
    foreach ($rows as $row) {
        $existing[$row['day']] = $row;
    }
    $keys = array_keys($existing);
    sort($keys);

    $fmt = $byMonth ? 'Y-m' : 'Y-m-d';
    $step = $byMonth ? '+1 month' : '+1 day';
    $cursor = DateTimeImmutable::createFromFormat('!' . $fmt, $keys[0]);
    $end = DateTimeImmutable::createFromFormat('!' . $fmt, $keys[count($keys) - 1]);
    if ($cursor === false || $end === false) {
        return $rows;
    }

    $filled = [];
    while ($cursor <= $end) {
        $key = $cursor->format($fmt);
        $filled[] = $existing[$key] ?? [
            'day' => $key,
            'total' => 0,
            'full' => 0,
            'dkim_only' => 0,
            'spf_only' => 0,
            'fail' => 0,
        ];
        $cursor = $cursor->modify($step);
    }
    return $filled;
}

// Top source IPs by message volume with pass/fail counts and how they aligned.
function reportTrendsTopSenders(PDO $db, array $filters, int $limit): array
{
    [$where, $params] = reportRecordsFilterClause($filters, 'rr');
    // first_seen has to look past the selected window — a plain MIN(day_ts)
    // would only report the source's first day inside the range, which is no
    // use for spotting senders that are genuinely new.
    $sql = "SELECT rr.source_ip AS source_ip,
                SUM(rr.message_count) AS total,
                SUM(CASE WHEN rr.dkim_aligned = 1 OR rr.spf_aligned = 1 THEN rr.message_count ELSE 0 END) AS pass,
                SUM(CASE WHEN rr.dkim_aligned = 0 AND rr.spf_aligned = 0 THEN rr.message_count ELSE 0 END) AS fail,
                SUM(CASE WHEN rr.dkim_aligned = 1 THEN rr.message_count ELSE 0 END) AS dkim,
                SUM(CASE WHEN rr.spf_aligned = 1 THEN rr.message_count ELSE 0 END) AS spf,
                (SELECT MIN(f.day_ts) FROM report_records f
                    WHERE f.source_ip = rr.source_ip AND f.day_ts > 0) AS first_seen
            FROM report_records rr" . $where
        . " AND rr.source_ip <> '' GROUP BY rr.source_ip ORDER BY total DESC, rr.source_ip LIMIT :limit";
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = [
            'source_ip' => (string)$row['source_ip'],
            'total' => (int)$row['total'],
            'pass' => (int)$row['pass'],
            'fail' => (int)$row['fail'],
            'dkim' => (int)$row['dkim'],
            'spf' => (int)$row['spf'],
            'first_seen' => (int)($row['first_seen'] ?? 0),
        ];
    }
    return $rows;
}

// Message volume per DMARC disposition (none / quarantine / reject), which
// answers whether the published policy is actually acting on failures.
function reportTrendsDispositions(PDO $db, array $filters): array
{
    [$where, $params] = reportRecordsFilterClause($filters);
    $sql = "SELECT disposition, SUM(message_count) AS total
            FROM report_records" . $where . ' GROUP BY disposition';
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->execute();

    $totals = ['none' => 0, 'quarantine' => 0, 'reject' => 0, 'other' => 0];
    foreach ($stmt as $row) {
        $key = strtolower(trim((string)$row['disposition']));
        if (!array_key_exists($key, $totals)) {
            $key = 'other';
        }
        $totals[$key] += (int)$row['total'];
    }
    return $totals;
}

// Headline totals: messages, pass rate, sources and domains.
function reportTrendsSummary(PDO $db, array $filters): array
{
    [$where, $params] = reportRecordsFilterClause($filters);
    $sql = "SELECT
                SUM(message_count) AS total,
                SUM(CASE WHEN dkim_aligned = 1 OR spf_aligned = 1 THEN message_count ELSE 0 END) AS pass,
                COUNT(DISTINCT source_ip) AS sources,
                COUNT(DISTINCT domain) AS domains
            FROM report_records" . $where;
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->execute();
    $row = $stmt->fetch() ?: [];
    $total = (int)($row['total'] ?? 0);
    $pass = (int)($row['pass'] ?? 0);
    return [
        'total' => $total,
        'pass' => $pass,
        'fail' => $total - $pass,
        'pass_rate' => $total > 0 ? round($pass / $total * 100, 1) : 0.0,
        'sources' => (int)($row['sources'] ?? 0),
        'domains' => (int)($row['domains'] ?? 0),
    ];
}

// Per-domain message totals for a single source IP.
function reportSenderByDomain(PDO $db, array $filters): array
{
    [$where, $params] = reportRecordsFilterClause($filters);
    $sql = "SELECT domain,
                SUM(message_count) AS total,
                SUM(CASE WHEN dkim_aligned = 1 OR spf_aligned = 1 THEN message_count ELSE 0 END) AS pass,
                SUM(CASE WHEN dkim_aligned = 0 AND spf_aligned = 0 THEN message_count ELSE 0 END) AS fail
            FROM report_records" . $where . ' GROUP BY domain ORDER BY total DESC';
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->execute();
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = [
            'domain' => (string)$row['domain'],
            'total' => (int)$row['total'],
            'pass' => (int)$row['pass'],
            'fail' => (int)$row['fail'],
        ];
    }
    return $rows;
}

// Newest day covered by any indexed record, or 0 when there is none.
function reportRecordsLatestDay(PDO $db): int
{
    $row = $db->query('SELECT MAX(day_ts) AS latest FROM report_records WHERE day_ts > 0')->fetch();
    return (int)($row['latest'] ?? 0);
}

// Totals for a window: messages, pass, fail, distinct sources and domains.
function reportHealthWindow(PDO $db, array $filters): array
{
    [$where, $params] = reportRecordsFilterClause($filters);
    $sql = "SELECT
                SUM(message_count) AS total,
                SUM(CASE WHEN dkim_aligned = 1 OR spf_aligned = 1 THEN message_count ELSE 0 END) AS pass,
                COUNT(DISTINCT source_ip) AS sources,
                COUNT(DISTINCT domain) AS domains,
                COUNT(DISTINCT CASE WHEN dkim_aligned = 0 AND spf_aligned = 0 THEN source_ip END) AS failing_sources
            FROM report_records" . $where;
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->execute();
    $row = $stmt->fetch() ?: [];
    $total = (int)($row['total'] ?? 0);
    $pass = (int)($row['pass'] ?? 0);
    return [
        'total' => $total,
        'pass' => $pass,
        'fail' => $total - $pass,
        'pass_rate' => $total > 0 ? round($pass / $total * 100, 1) : 0.0,
        'sources' => (int)($row['sources'] ?? 0),
        'domains' => (int)($row['domains'] ?? 0),
        'failing_sources' => (int)($row['failing_sources'] ?? 0),
    ];
}

// Per-domain totals in a window, biggest volume first.
function reportHealthDomains(PDO $db, array $filters, int $limit): array
{
    [$where, $params] = reportRecordsFilterClause($filters);
    $sql = "SELECT domain,
                SUM(message_count) AS total,
                SUM(CASE WHEN dkim_aligned = 1 OR spf_aligned = 1 THEN message_count ELSE 0 END) AS pass,
                SUM(CASE WHEN dkim_aligned = 0 AND spf_aligned = 0 THEN message_count ELSE 0 END) AS fail,
                COUNT(DISTINCT source_ip) AS sources,
                SUM(CASE WHEN disposition IN ('quarantine', 'reject') THEN message_count ELSE 0 END) AS enforced
            FROM report_records" . $where
        . " AND domain <> '' GROUP BY domain ORDER BY total DESC LIMIT :limit";
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    $rows = [];
    foreach ($stmt as $row) {
        $total = (int)$row['total'];
        $pass = (int)$row['pass'];
        $rows[] = [
            'domain' => (string)$row['domain'],
            'total' => $total,
            'pass' => $pass,
            'fail' => (int)$row['fail'],
            'pass_rate' => $total > 0 ? round($pass / $total * 100, 1) : 0.0,
            'sources' => (int)$row['sources'],
            'enforced' => (int)$row['enforced'],
        ];
    }
    return $rows;
}

// Source IPs sending unaligned mail in a day window, worst volume first. Each
// row carries the day the IP was first seen anywhere in the index, so the view
// can flag senders that only started showing up recently.
function reportHealthFailingSources(PDO $db, array $filters, int $limit): array
{
    [$where, $params] = reportRecordsFilterClause($filters, 'rr');
    $sql = "SELECT rr.source_ip AS source_ip,
                SUM(rr.message_count) AS total,
                SUM(CASE WHEN rr.dkim_aligned = 0 AND rr.spf_aligned = 0 THEN rr.message_count ELSE 0 END) AS fail,
                COUNT(DISTINCT rr.domain) AS domains,
                (SELECT MIN(f.day_ts) FROM report_records f
                    WHERE f.source_ip = rr.source_ip AND f.day_ts > 0) AS first_seen
            FROM report_records rr" . $where
        . " AND rr.source_ip <> '' GROUP BY rr.source_ip HAVING fail > 0
            ORDER BY fail DESC, total DESC LIMIT :limit";
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    $rows = [];
    foreach ($stmt as $row) {
        $total = (int)$row['total'];
        $fail = (int)$row['fail'];
        $rows[] = [
            'source_ip' => (string)$row['source_ip'],
            'total' => $total,
            'fail' => $fail,
            'pass' => $total - $fail,
            'domains' => (int)$row['domains'],
            'first_seen' => (int)($row['first_seen'] ?? 0),
        ];
    }
    return $rows;
}

// How many source IPs appear for the first time inside a window. Org and domain
// narrow which sources count, but the window itself must not sit in the WHERE
// clause: "first seen" has to look at the whole history, so it belongs in
// HAVING. The bounds must bind as integers — MIN(day_ts) drops the column's
// integer affinity, and a text-bound value would compare as INTEGER < TEXT and
// never match.
function reportHealthNewSourceCount(PDO $db, array $filters, int $startTs, int $endTs): int
{
    [$where, $params] = reportRecordsFilterClause(['org' => $filters['org'] ?? '', 'domain' => $filters['domain'] ?? '']);
    $sql = "SELECT COUNT(*) AS new_sources FROM (
                SELECT source_ip FROM report_records" . $where
        . " AND source_ip <> '' GROUP BY source_ip
                HAVING MIN(day_ts) >= :start AND MIN(day_ts) <= :end
            )";
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->bindValue(':start', $startTs, PDO::PARAM_INT);
    $stmt->bindValue(':end', $endTs, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch() ?: [];
    return (int)($row['new_sources'] ?? 0);
}

// Alignment buckets of the trends chart mapped to their record condition.
function reportAlignmentConditions(): array
{
    return [
        'full' => 'dkim_aligned = 1 AND spf_aligned = 1',
        'dkim_only' => 'dkim_aligned = 1 AND spf_aligned = 0',
        'spf_only' => 'dkim_aligned = 0 AND spf_aligned = 1',
        'fail' => 'dkim_aligned = 0 AND spf_aligned = 0',
    ];
}

// Reports behind one chart column: a day ("YYYY-MM-DD") or month ("YYYY-MM")
// bucket, optionally narrowed to a single alignment segment.
function reportBucketReports(PDO $db, string $bucket, string $alignment, array $filters): array
{
    [$where, $params] = reportRecordsFilterClause($filters, 'rr');

    $format = strlen($bucket) > 7 ? '%Y-%m-%d' : '%Y-%m';
    $where .= " AND strftime('{$format}', rr.day_ts, 'unixepoch') = :bucket";
    $params[':bucket'] = $bucket;

    // The alignment columns exist only on report_records, so they need no alias.
    $conditions = reportAlignmentConditions();
    if (isset($conditions[$alignment])) {
        $where .= ' AND ' . $conditions[$alignment];
    }

    $sql = "SELECT rr.report_path AS path,
                SUM(rr.message_count) AS total,
                COUNT(DISTINCT rr.source_ip) AS sources,
                r.org AS org, r.domain AS domain, r.begin_ts AS begin_ts, r.end_ts AS end_ts
            FROM report_records rr
            LEFT JOIN reports r ON r.path = rr.report_path" . $where
        . ' GROUP BY rr.report_path ORDER BY total DESC';
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->execute();

    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = [
            'path' => (string)$row['path'],
            'total' => (int)$row['total'],
            'sources' => (int)$row['sources'],
            'org' => (string)($row['org'] ?? ''),
            'domain' => (string)($row['domain'] ?? ''),
            'begin_ts' => $row['begin_ts'] !== null ? (int)$row['begin_ts'] : null,
            'end_ts' => $row['end_ts'] !== null ? (int)$row['end_ts'] : null,
        ];
    }
    return $rows;
}

// Reports a single source IP appears in, with totals.
function reportSenderReports(PDO $db, array $filters): array
{
    [$where, $params] = reportRecordsFilterClause($filters, 'rr');
    $sql = "SELECT rr.report_path AS path,
                SUM(rr.message_count) AS total,
                r.org AS org, r.domain AS domain, r.begin_ts AS begin_ts, r.end_ts AS end_ts
            FROM report_records rr
            LEFT JOIN reports r ON r.path = rr.report_path" . $where
        . ' GROUP BY rr.report_path ORDER BY total DESC';
    $stmt = $db->prepare($sql);
    reportBindParams($stmt, $params);
    $stmt->execute();
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = [
            'path' => (string)$row['path'],
            'total' => (int)$row['total'],
            'org' => (string)($row['org'] ?? ''),
            'domain' => (string)($row['domain'] ?? ''),
            'begin_ts' => $row['begin_ts'] !== null ? (int)$row['begin_ts'] : null,
            'end_ts' => $row['end_ts'] !== null ? (int)$row['end_ts'] : null,
        ];
    }
    return $rows;
}
