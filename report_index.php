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

// All report summary rows, newest first.
function reportIndexSummaries(PDO $db): array
{
    $rows = [];
    foreach ($db->query('SELECT * FROM reports ORDER BY sort_ts DESC') as $row) {
        $rows[] = $row;
    }
    return $rows;
}

// Build the WHERE clause and params for year/month/org filters.
function reportIndexFilterClause(?string $year, ?string $month, ?string $org): array
{
    $clauses = [];
    $params = [];
    if ($year !== null && $year !== '') {
        $clauses[] = "strftime('%Y', sort_ts, 'unixepoch') = :year";
        $params[':year'] = $year;
    }
    if ($month !== null && $month !== '') {
        $clauses[] = "strftime('%m', sort_ts, 'unixepoch') = :month";
        $params[':month'] = $month;
    }
    if ($org !== null && $org !== '') {
        $clauses[] = 'org = :org';
        $params[':org'] = $org;
    }
    $where = $clauses === [] ? '' : (' WHERE ' . implode(' AND ', $clauses));
    return [$where, $params];
}

// Fetch one filtered, paginated page of report rows plus the total.
function reportIndexQueryPage(PDO $db, ?string $year, ?string $month, ?string $org, int $limit, int $offset): array
{
    [$where, $params] = reportIndexFilterClause($year, $month, $org);

    $countStmt = $db->prepare('SELECT COUNT(*) FROM reports' . $where);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = 'SELECT * FROM reports' . $where . ' ORDER BY sort_ts DESC LIMIT :limit OFFSET :offset';
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
}

// Distinct year, month and organization values for the filters.
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

    return ['years' => $years, 'months' => $months, 'orgs' => $orgs];
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

// Build the WHERE clause and params for record-level queries.
function reportRecordsFilterClause(?string $year, ?string $month, ?string $org, ?string $ip = null, string $alias = ''): array
{
    $col = $alias !== '' ? $alias . '.' : '';
    $clauses = [$col . 'day_ts > 0'];
    $params = [];
    if ($year !== null && $year !== '') {
        $clauses[] = "strftime('%Y', " . $col . "day_ts, 'unixepoch') = :year";
        $params[':year'] = $year;
    }
    if ($month !== null && $month !== '') {
        $clauses[] = "strftime('%m', " . $col . "day_ts, 'unixepoch') = :month";
        $params[':month'] = $month;
    }
    if ($org !== null && $org !== '') {
        $clauses[] = $col . 'org = :org';
        $params[':org'] = $org;
    }
    if ($ip !== null && $ip !== '') {
        $clauses[] = $col . 'source_ip = :ip';
        $params[':ip'] = $ip;
    }
    return [' WHERE ' . implode(' AND ', $clauses), $params];
}

// Per-day message volume split by DMARC alignment.
function reportTrendsTimeseries(PDO $db, ?string $year, ?string $month, ?string $org, ?string $ip = null): array
{
    [$where, $params] = reportRecordsFilterClause($year, $month, $org, $ip);
    $sql = "SELECT strftime('%Y-%m-%d', day_ts, 'unixepoch') AS day,
                SUM(message_count) AS total,
                SUM(CASE WHEN dkim_aligned = 1 AND spf_aligned = 1 THEN message_count ELSE 0 END) AS full_pass,
                SUM(CASE WHEN dkim_aligned = 1 AND spf_aligned = 0 THEN message_count ELSE 0 END) AS dkim_only,
                SUM(CASE WHEN dkim_aligned = 0 AND spf_aligned = 1 THEN message_count ELSE 0 END) AS spf_only,
                SUM(CASE WHEN dkim_aligned = 0 AND spf_aligned = 0 THEN message_count ELSE 0 END) AS fail
            FROM report_records" . $where . ' GROUP BY day ORDER BY day';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
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
    return $rows;
}

// Top source IPs by message volume with pass/fail counts.
function reportTrendsTopSenders(PDO $db, ?string $year, ?string $month, ?string $org, int $limit): array
{
    [$where, $params] = reportRecordsFilterClause($year, $month, $org);
    $sql = "SELECT source_ip,
                SUM(message_count) AS total,
                SUM(CASE WHEN dkim_aligned = 1 OR spf_aligned = 1 THEN message_count ELSE 0 END) AS pass,
                SUM(CASE WHEN dkim_aligned = 0 AND spf_aligned = 0 THEN message_count ELSE 0 END) AS fail
            FROM report_records" . $where
        . " AND source_ip <> '' GROUP BY source_ip ORDER BY total DESC, source_ip LIMIT :limit";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    $rows = [];
    foreach ($stmt as $row) {
        $rows[] = [
            'source_ip' => (string)$row['source_ip'],
            'total' => (int)$row['total'],
            'pass' => (int)$row['pass'],
            'fail' => (int)$row['fail'],
        ];
    }
    return $rows;
}

// Headline totals: messages, pass rate, sources and domains.
function reportTrendsSummary(PDO $db, ?string $year, ?string $month, ?string $org, ?string $ip = null): array
{
    [$where, $params] = reportRecordsFilterClause($year, $month, $org, $ip);
    $sql = "SELECT
                SUM(message_count) AS total,
                SUM(CASE WHEN dkim_aligned = 1 OR spf_aligned = 1 THEN message_count ELSE 0 END) AS pass,
                COUNT(DISTINCT source_ip) AS sources,
                COUNT(DISTINCT domain) AS domains
            FROM report_records" . $where;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
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
function reportSenderByDomain(PDO $db, string $ip, ?string $year, ?string $month, ?string $org): array
{
    [$where, $params] = reportRecordsFilterClause($year, $month, $org, $ip);
    $sql = "SELECT domain,
                SUM(message_count) AS total,
                SUM(CASE WHEN dkim_aligned = 1 OR spf_aligned = 1 THEN message_count ELSE 0 END) AS pass,
                SUM(CASE WHEN dkim_aligned = 0 AND spf_aligned = 0 THEN message_count ELSE 0 END) AS fail
            FROM report_records" . $where . ' GROUP BY domain ORDER BY total DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
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

// Reports a single source IP appears in, with totals.
function reportSenderReports(PDO $db, string $ip, ?string $year, ?string $month, ?string $org): array
{
    [$where, $params] = reportRecordsFilterClause($year, $month, $org, $ip, 'rr');
    $sql = "SELECT rr.report_path AS path,
                SUM(rr.message_count) AS total,
                r.org AS org, r.domain AS domain, r.begin_ts AS begin_ts, r.end_ts AS end_ts
            FROM report_records rr
            LEFT JOIN reports r ON r.path = rr.report_path" . $where
        . ' GROUP BY rr.report_path ORDER BY total DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
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
