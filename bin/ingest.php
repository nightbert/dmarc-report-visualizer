<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';
require_once __DIR__ . '/../public/_lib.php';

$inboxDir = resolveDataPath('INBOX_DIR', '/data/inbox', 'inbox');
$reportsDir = resolveDataPath('REPORTS_DIR', '/data/reports', 'reports');
$statusFile = resolveDataPath('STATUS_FILE', '/data/status.json', 'status.json');

if (!is_dir($inboxDir)) {
    mkdir($inboxDir, 0775, true);
}
if (!is_dir($reportsDir)) {
    mkdir($reportsDir, 0775, true);
}

$entries = @scandir($inboxDir);
if ($entries === false) {
    fwrite(STDERR, "Could not read inbox: $inboxDir\n");
    exit(0);
}

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $path = $inboxDir . DIRECTORY_SEPARATOR . $entry;
    if (is_dir($path)) {
        updateStatus($statusFile, $entry, 'ignored', 100, 'Removed directory from inbox.');
        removeDir($path);
        continue;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'zip') {
        updateStatus($statusFile, $entry, 'queued', 5, 'ZIP queued.');
        processZip($path, $reportsDir, $statusFile);
        continue;
    }

    if ($ext === 'xml') {
        updateStatus($statusFile, $entry, 'processing', 40, 'Processing XML.');
        if (processXml($path, $reportsDir, basename($path), $statusFile, $entry) === 'stored') {
            updateStatus($statusFile, $entry, 'done', 100, 'XML stored.');
        }
        continue;
    }

    if ($ext === 'gz') {
        updateStatus($statusFile, $entry, 'queued', 10, 'GZ queued.');
        processGz($path, $reportsDir, $statusFile);
        continue;
    }

    if ($ext === 'eml') {
        updateStatus($statusFile, $entry, 'queued', 10, 'EML queued.');
        processEml($path, $reportsDir, $statusFile);
        continue;
    }

    if ($ext === 'msg') {
        updateStatus($statusFile, $entry, 'queued', 10, 'MSG queued.');
        processMsg($path, $reportsDir, $statusFile);
        continue;
    }

    updateStatus($statusFile, $entry, 'ignored', 100, 'Not a ZIP/XML/EML/MSG file.');
    @unlink($path);
}

$retentionMonths = reportRetentionMonths();
if ($retentionMonths > 0) {
    purgeOldReports($reportsDir, $retentionMonths);
}

function processZip(string $zipPath, string $reportsDir, string $statusFile, ?string $statusKey = null): string
{
    $statusName = $statusKey ?? basename($zipPath);
    $nested = $statusKey !== null;
    $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dmarc_' . bin2hex(random_bytes(6));
    if (!mkdir($tmpDir, 0775, true)) {
        updateStatus($statusFile, $statusName, 'error', 100, 'Failed to create temp directory.');
        @unlink($zipPath);
        return 'failed';
    }

    if (!$nested) {
        updateStatus($statusFile, $statusName, 'extracting', 35, 'Extracting ZIP.');
    }
    $cmd = 'unzip -qq ' . escapeshellarg($zipPath) . ' -d ' . escapeshellarg($tmpDir);
    exec($cmd, $output, $code);

    $best = 'failed';
    if ($code === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir));
        if (!$nested) {
            updateStatus($statusFile, $statusName, 'processing', 70, 'Processing XML inside ZIP.');
        }
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $src = $fileInfo->getPathname();
            $base = $fileInfo->getBasename();
            $ext = strtolower($fileInfo->getExtension());
            $result = '';
            if ($ext === 'xml') {
                $result = processXml($src, $reportsDir, $base, $statusFile, $statusName);
            } elseif ($ext === 'gz' && str_ends_with(strtolower($base), '.xml.gz')) {
                $result = processGz($src, $reportsDir, $statusFile, $statusName);
            }
            $best = mergeProcResult($best, $result);
        }
        if ($best === 'failed') {
            updateStatus($statusFile, $statusName, 'error', 100, 'ZIP contained no DMARC XML/GZ.');
        } elseif ($best === 'duplicate') {
            updateStatus($statusFile, $statusName, 'duplicate', 100, 'Report already exists.');
        } elseif (!$nested) {
            updateStatus($statusFile, $statusName, 'done', 100, 'ZIP processed.');
        }
    } else {
        updateStatus($statusFile, $statusName, 'error', 100, 'Failed to extract ZIP.');
    }

    removeDir($tmpDir);
    @unlink($zipPath);
    return $best;
}

function mergeProcResult(string $current, string $next): string
{
    $rank = ['failed' => 0, 'duplicate' => 1, 'stored' => 2];
    $c = $rank[$current] ?? 0;
    $n = $rank[$next] ?? 0;
    return $n > $c ? $next : $current;
}

function processXml(string $xmlPath, string $reportsDir, string $preferredName, string $statusFile = '', ?string $statusKey = null): string
{
    $name = sanitizeFileName($preferredName);
    if ($name === '') {
        $name = 'report.xml';
    }

    $fingerprint = reportFingerprintFromXmlFile($xmlPath);
    if ($fingerprint !== '' && reportFingerprintKnown($reportsDir, $fingerprint)) {
        if ($statusFile !== '' && $statusKey !== null) {
            updateStatus($statusFile, $statusKey, 'duplicate', 100, 'Report already exists: ' . $name);
        }
        @unlink($xmlPath);
        return 'duplicate';
    }

    $timestamp = extractReportTimestamp($xmlPath);
    $year = date('Y', $timestamp);
    $month = date('m', $timestamp);

    $destDir = $reportsDir . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }

    $destPath = $destDir . DIRECTORY_SEPARATOR . $name;
    $destPath = ensureUniquePath($destPath);

    if (!@rename($xmlPath, $destPath)) {
        if (@copy($xmlPath, $destPath)) {
            @unlink($xmlPath);
        }
    }

    @chmod($destPath, 0644);
    return 'stored';
}

function reportFingerprintFromXmlFile(string $xmlPath): string
{
    $content = @file_get_contents($xmlPath);
    if ($content === false || $content === '') {
        return '';
    }
    $content = preg_replace('/^\\xEF\\xBB\\xBF/', '', $content);
    $xml = loadXml($content);
    if (!$xml instanceof SimpleXMLElement) {
        return '';
    }

    $reportId = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="report_id"]');
    $domain = xmlValue($xml, '//*[local-name()="policy_published"]/*[local-name()="domain"]');
    $begin = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="begin"]');
    $end = xmlValue($xml, '//*[local-name()="report_metadata"]/*[local-name()="date_range"]/*[local-name()="end"]');

    if ($reportId === '' || $domain === '' || $begin === '' || $end === '') {
        return '';
    }

    return strtolower($reportId . '|' . $domain . '|' . $begin . '|' . $end);
}

function reportFingerprintKnown(string $reportsDir, string $fingerprint): bool
{
    if ($fingerprint === '' || !is_dir($reportsDir)) {
        return false;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($reportsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'xml') {
            continue;
        }
        if (reportFingerprintFromXmlFile($fileInfo->getPathname()) === $fingerprint) {
            return true;
        }
    }
    return false;
}

function processGz(string $gzPath, string $reportsDir, string $statusFile, ?string $statusKey = null): string
{
    $baseName = basename($gzPath);
    $statusName = $statusKey ?? $baseName;
    $nested = $statusKey !== null;
    if (strtolower(substr($baseName, -7)) !== '.xml.gz') {
        updateStatus($statusFile, $statusName, 'ignored', 100, 'Not an XML.GZ file.');
        @unlink($gzPath);
        return 'failed';
    }

    $magic = readFileMagic($gzPath, 64);
    if ($magic !== '' && str_starts_with($magic, "PK\x03\x04")) {
        if (!$nested) {
            updateStatus($statusFile, $statusName, 'queued', 10, 'ZIP detected in XML.GZ.');
        }
        return processZip($gzPath, $reportsDir, $statusFile, $statusName);
    }
    if ($magic !== '' && isLikelyXml($magic)) {
        if (!$nested) {
            updateStatus($statusFile, $statusName, 'processing', 40, 'XML detected in XML.GZ.');
        }
        $result = processXml($gzPath, $reportsDir, preg_replace('/\\.gz$/i', '', $baseName), $statusFile, $statusName);
        @unlink($gzPath);
        if ($result === 'stored' && !$nested) {
            updateStatus($statusFile, $statusName, 'done', 100, 'XML stored.');
        }
        return $result;
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'dmarc_xml_');
    if ($tmpFile === false) {
        updateStatus($statusFile, $statusName, 'error', 100, 'Failed to create temp file.');
        @unlink($gzPath);
        return 'failed';
    }

    if (!$nested) {
        updateStatus($statusFile, $statusName, 'extracting', 40, 'Decompressing XML.GZ.');
    }
    $decompressed = false;
    if (function_exists('gzopen')) {
        $in = @gzopen($gzPath, 'rb');
        if ($in !== false) {
            $out = @fopen($tmpFile, 'wb');
            if ($out === false) {
                updateStatus($statusFile, $statusName, 'error', 100, 'Failed to write temp file.');
                gzclose($in);
                @unlink($gzPath);
                @unlink($tmpFile);
                return 'failed';
            }

            while (!gzeof($in)) {
                $chunk = gzread($in, 8192);
                if ($chunk === false) {
                    break;
                }
                fwrite($out, $chunk);
            }

            fclose($out);
            gzclose($in);
            $decompressed = true;
        }
    }

    if (!$decompressed) {
        $cmd = 'gzip -dc ' . escapeshellarg($gzPath) . ' > ' . escapeshellarg($tmpFile);
        exec($cmd, $output, $code);
        if ($code !== 0) {
            updateStatus($statusFile, $statusName, 'error', 100, 'Failed to open GZ.');
            @unlink($gzPath);
            @unlink($tmpFile);
            return 'failed';
        }
    }

    $preferredName = preg_replace('/\\.gz$/i', '', $baseName);
    if (!$nested) {
        updateStatus($statusFile, $statusName, 'processing', 80, 'Processing XML.');
    }
    $result = processXml($tmpFile, $reportsDir, $preferredName, $statusFile, $statusName);

    @unlink($tmpFile);
    @unlink($gzPath);
    if ($result === 'stored' && !$nested) {
        updateStatus($statusFile, $statusName, 'done', 100, 'XML.GZ processed.');
    }
    return $result;
}

function readFileMagic(string $path, int $bytes): string
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    $data = @fread($fh, $bytes);
    fclose($fh);
    return is_string($data) ? $data : '';
}

function isLikelyXml(string $buffer): bool
{
    $trimmed = ltrim($buffer);
    return str_starts_with($trimmed, '<') || str_starts_with($trimmed, '<?xml');
}

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

function extractReportTimestamp(string $xmlPath): int
{
    $content = @file_get_contents($xmlPath);
    if ($content === false) {
        return time();
    }

    $xml = @simplexml_load_string($content);
    if ($xml === false) {
        return filemtime($xmlPath) ?: time();
    }

    $begin = (string)($xml->report_metadata->date_range->begin ?? '');
    if (ctype_digit($begin)) {
        return (int)$begin;
    }

    $end = (string)($xml->report_metadata->date_range->end ?? '');
    if (ctype_digit($end)) {
        return (int)$end;
    }

    return filemtime($xmlPath) ?: time();
}

function sanitizeFileName(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name ?? '');
    return trim($name, '._-');
}

function ensureUniquePath(string $path): string
{
    if (!file_exists($path)) {
        return $path;
    }

    $dir = dirname($path);
    $base = pathinfo($path, PATHINFO_FILENAME);
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $counter = 1;

    do {
        $suffix = '_' . $counter;
        $name = $base . $suffix . ($ext !== '' ? '.' . $ext : '');
        $candidate = $dir . DIRECTORY_SEPARATOR . $name;
        $counter++;
    } while (file_exists($candidate));

    return $candidate;
}

function processEml(string $emlPath, string $reportsDir, string $statusFile, ?string $statusKey = null): array
{
    $baseName = $statusKey ?? basename($emlPath);
    updateStatus($statusFile, $baseName, 'processing', 30, 'Parsing email.');

    $content = @file_get_contents($emlPath);
    if ($content === false) {
        updateStatus($statusFile, $baseName, 'error', 100, 'Failed to read email.');
        @unlink($emlPath);
        return ['processed' => 0, 'failed' => 1];
    }

    $parts = collectEmlPartsBest($emlPath, $content);
    if (empty($parts)) {
        updateStatus($statusFile, $baseName, 'ignored', 100, 'Could not parse email structure.');
        @unlink($emlPath);
        return ['processed' => 0, 'failed' => 1];
    }

    $attachments = filterDmarcAttachments($parts);
    if (empty($attachments)) {
        $partCount = count($parts);
        $seenDetails = [];
        foreach ($parts as $p) {
            $n = (string)($p['name'] ?? '');
            $ct = (string)($p['content_type'] ?? '');
            $seenDetails[] = ($n !== '' ? $n : '(no name)') . ' [' . ($ct !== '' ? $ct : '?') . ']';
        }
        $hint = $seenDetails ? ' Found: ' . implode(', ', array_slice($seenDetails, 0, 5)) : '';
        error_log("processEml: no DMARC attachments in {$baseName}.{$hint}");
        updateStatus($statusFile, $baseName, 'ignored', 100, "No DMARC attachments in email ({$partCount} part(s)).{$hint}");
        @unlink($emlPath);
        return ['processed' => 0, 'failed' => 1];
    }

    $processed = 0;
    $failed = 0;
    $duplicates = 0;
    $skipReasons = [];
    foreach ($attachments as $attachment) {
        $fileName = (string)($attachment['name'] ?? '');
        $data = (string)($attachment['data'] ?? '');
        $contentType = strtolower((string)($attachment['content_type'] ?? ''));
        $label = ($fileName !== '' ? $fileName : '(no name)');
        if ($data === '') {
            $failed++;
            $skipReasons[] = $label . ': empty data';
            continue;
        }

        $lowerName = strtolower($fileName);
        $ext = $lowerName !== '' ? pathinfo($lowerName, PATHINFO_EXTENSION) : '';
        $isXmlGz = str_ends_with($lowerName, '.xml.gz');

        $magic = substr($data, 0, 4);
        $kind = '';
        if (str_starts_with($magic, "\x1F\x8B")) {
            $kind = 'gz';
        } elseif (str_starts_with($magic, "PK\x03\x04")) {
            $kind = 'zip';
        } elseif (isLikelyXml($magic)) {
            $kind = 'xml';
        } elseif ($ext === 'zip' || str_contains($contentType, 'zip')) {
            $kind = 'zip';
        } elseif (($ext === 'gz' && $isXmlGz) || str_contains($contentType, 'gzip')) {
            $kind = 'gz';
        } elseif ($ext === 'xml' || str_contains($contentType, 'xml')) {
            $kind = 'xml';
        }

        if ($kind === '') {
            $failed++;
            $skipReasons[] = $label . ': unrecognized kind (ct=' . ($contentType ?: '?') . ')';
            continue;
        }

        $stem = $fileName !== '' ? pathinfo($fileName, PATHINFO_FILENAME) : '';
        if ($stem === '') {
            $stem = 'attachment';
        }
        if ($kind === 'zip' && $ext !== 'zip') {
            $fileName = $stem . '.zip';
        } elseif ($kind === 'gz' && !$isXmlGz) {
            $fileName = $stem . '.xml.gz';
        } elseif ($kind === 'xml' && $ext !== 'xml') {
            $fileName = $stem . '.xml';
        }

        $safeName = sanitizeFileName($fileName);
        if ($safeName === '') {
            $safeName = $kind === 'gz' ? 'attachment.xml.gz' : 'attachment.' . $kind;
        }

        $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dmarc_eml_' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0775, true)) {
            $failed++;
            $skipReasons[] = $label . ': mkdir failed';
            continue;
        }
        $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . $safeName;
        if (@file_put_contents($tmpPath, $data) === false) {
            removeDir($tmpDir);
            $failed++;
            $skipReasons[] = $label . ': write failed';
            continue;
        }

        updateStatus($statusFile, $baseName, 'processing', 60, "Processing attachment: {$safeName}");
        $result = 'failed';
        if ($kind === 'zip') {
            $result = processZip($tmpPath, $reportsDir, $statusFile, $baseName);
        } elseif ($kind === 'gz') {
            $result = processGz($tmpPath, $reportsDir, $statusFile, $baseName);
        } else {
            $result = processXml($tmpPath, $reportsDir, $safeName, $statusFile, $baseName);
        }
        if ($result === 'stored') {
            $processed++;
        } elseif ($result === 'duplicate') {
            $duplicates++;
        } else {
            $failed++;
        }

        removeDir($tmpDir);
    }

    if ($processed > 0 && $failed === 0 && $duplicates === 0) {
        if ($statusKey === null) {
            updateStatus($statusFile, $baseName, 'done', 100, "Email processed, {$processed} attachment(s) extracted.");
        }
    } elseif ($processed > 0 && $duplicates > 0 && $failed === 0) {
        updateStatus($statusFile, $baseName, 'done', 100, "{$processed} new, {$duplicates} duplicate.");
    } elseif ($processed === 0 && $duplicates > 0 && $failed === 0) {
        updateStatus($statusFile, $baseName, 'duplicate', 100, "Report already exists ({$duplicates} attachment(s)).");
    } elseif ($failed > 0) {
        $reasonText = !empty($skipReasons) ? ' ' . implode('; ', array_slice($skipReasons, 0, 3)) : '';
        error_log("processEml: failures for {$baseName}.{$reasonText}");
        $parts = [];
        if ($processed > 0) $parts[] = "{$processed} stored";
        if ($duplicates > 0) $parts[] = "{$duplicates} duplicate";
        $parts[] = "{$failed} failed";
        $msg = implode(', ', $parts) . '.' . $reasonText;
        updateStatus($statusFile, $baseName, 'error', 100, $msg);
    } else {
        $details = [];
        foreach ($attachments as $a) {
            $n = (string)($a['name'] ?? '?');
            $ct = (string)($a['content_type'] ?? '?');
            $details[] = $n . ' [' . $ct . ']';
        }
        $detailText = !empty($details) ? ' Tried: ' . implode(', ', array_slice($details, 0, 3)) : '';
        error_log("processEml: dispatch produced nothing for {$baseName}.{$detailText}");
        updateStatus($statusFile, $baseName, 'ignored', 100, 'No usable DMARC attachments found.' . $detailText);
    }
    @unlink($emlPath);
    return ['processed' => $processed, 'failed' => $failed, 'duplicates' => $duplicates];
}

function processMsg(string $msgPath, string $reportsDir, string $statusFile): void
{
    $baseName = basename($msgPath);
    updateStatus($statusFile, $baseName, 'extracting', 20, 'Converting MSG to EML.');

    if (!isMsgConvertAvailable()) {
        updateStatus($statusFile, $baseName, 'error', 100, 'msgconvert not installed on this image.');
        @unlink($msgPath);
        return;
    }

    $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dmarc_msg_' . bin2hex(random_bytes(6));
    if (!mkdir($tmpDir, 0775, true)) {
        updateStatus($statusFile, $baseName, 'error', 100, 'Failed to create temp directory.');
        @unlink($msgPath);
        return;
    }

    $cmd = 'cd ' . escapeshellarg($tmpDir) . ' && msgconvert ' . escapeshellarg($msgPath) . ' 2>&1';
    $output = [];
    $code = 1;
    @exec($cmd, $output, $code);

    if ($code !== 0) {
        $tail = !empty($output) ? ' (' . implode(' | ', array_slice($output, -2)) . ')' : '';
        updateStatus($statusFile, $baseName, 'error', 100, 'msgconvert failed' . $tail);
        removeDir($tmpDir);
        @unlink($msgPath);
        return;
    }

    $emlFiles = glob($tmpDir . DIRECTORY_SEPARATOR . '*.eml') ?: [];
    if (empty($emlFiles)) {
        updateStatus($statusFile, $baseName, 'ignored', 100, 'msgconvert produced no EML.');
        removeDir($tmpDir);
        @unlink($msgPath);
        return;
    }

    $totalProcessed = 0;
    $totalFailed = 0;
    $totalDuplicates = 0;
    foreach ($emlFiles as $emlFile) {
        $result = processEml($emlFile, $reportsDir, $statusFile, $baseName);
        $totalProcessed += (int)($result['processed'] ?? 0);
        $totalFailed += (int)($result['failed'] ?? 0);
        $totalDuplicates += (int)($result['duplicates'] ?? 0);
    }

    removeDir($tmpDir);
    @unlink($msgPath);
    if ($totalProcessed > 0 && $totalFailed === 0 && $totalDuplicates === 0) {
        updateStatus($statusFile, $baseName, 'done', 100, "MSG processed, {$totalProcessed} report(s) extracted.");
    } elseif ($totalProcessed > 0 && $totalDuplicates > 0 && $totalFailed === 0) {
        updateStatus($statusFile, $baseName, 'done', 100, "{$totalProcessed} new, {$totalDuplicates} duplicate.");
    } elseif ($totalProcessed === 0 && $totalDuplicates > 0 && $totalFailed === 0) {
        updateStatus($statusFile, $baseName, 'duplicate', 100, "Report already exists ({$totalDuplicates}).");
    } elseif ($totalFailed > 0) {
        $parts = [];
        if ($totalProcessed > 0) $parts[] = "{$totalProcessed} stored";
        if ($totalDuplicates > 0) $parts[] = "{$totalDuplicates} duplicate";
        $parts[] = "{$totalFailed} failed";
        updateStatus($statusFile, $baseName, 'error', 100, implode(', ', $parts) . '.');
    }
}

function isMsgConvertAvailable(): bool
{
    $output = [];
    $code = 1;
    @exec('command -v msgconvert 2>/dev/null', $output, $code);
    return $code === 0 && !empty($output);
}

function collectEmlPartsBest(string $emlPath, string $raw): array
{
    if (extension_loaded('mailparse') && function_exists('mailparse_msg_parse_file')) {
        $parts = collectEmlPartsViaMailparse($emlPath);
        if (!empty($parts)) {
            return $parts;
        }
    }
    return collectEmlParts($raw);
}

function collectEmlPartsViaMailparse(string $emlPath): array
{
    $mime = @mailparse_msg_parse_file($emlPath);
    if ($mime === false) {
        return [];
    }

    $results = [];
    $structure = @mailparse_msg_get_structure($mime);
    if (!is_array($structure)) {
        @mailparse_msg_free($mime);
        return [];
    }

    foreach ($structure as $sectionId) {
        $section = @mailparse_msg_get_part($mime, $sectionId);
        if ($section === false) {
            continue;
        }
        $partData = @mailparse_msg_get_part_data($section);
        if (!is_array($partData)) {
            continue;
        }
        $contentType = strtolower((string)($partData['content-type'] ?? ''));
        if (str_starts_with($contentType, 'multipart/')) {
            continue;
        }
        $name = '';
        if (!empty($partData['disposition-filename'])) {
            $name = (string)$partData['disposition-filename'];
        } elseif (!empty($partData['content-name'])) {
            $name = (string)$partData['content-name'];
        }
        $name = decodeMimeHeaderValue($name);

        $body = @mailparse_msg_extract_part_file($section, $emlPath, null);
        if (!is_string($body)) {
            continue;
        }

        $results[] = [
            'name' => $name,
            'data' => $body,
            'content_type' => $contentType,
        ];
    }

    @mailparse_msg_free($mime);
    return $results;
}

function collectEmlParts(string $raw): array
{
    $normalized = preg_replace('/\r\n?/', "\n", $raw);
    if (!is_string($normalized) || $normalized === '') {
        return [];
    }
    $split = preg_split('/\n\n/', $normalized, 2);
    if (!is_array($split) || count($split) < 2) {
        return [];
    }
    [$headerBlock, $body] = $split;

    $headers = parseEmlHeaders($headerBlock);
    return parseEmlPart($headers, $body);
}

function filterDmarcAttachments(array $parts): array
{
    $attachments = [];
    foreach ($parts as $part) {
        $name = (string)($part['name'] ?? '');
        $data = (string)($part['data'] ?? '');
        $contentType = strtolower((string)($part['content_type'] ?? ''));
        if ($data === '') {
            continue;
        }

        $lowerName = strtolower($name);
        $ext = $lowerName !== '' ? pathinfo($lowerName, PATHINFO_EXTENSION) : '';
        $isXmlGz = str_ends_with($lowerName, '.xml.gz');
        $looksZip = $ext === 'zip' || str_contains($contentType, 'zip');
        $looksGz = ($ext === 'gz' && $isXmlGz) || str_contains($contentType, 'gzip');
        $looksXml = $ext === 'xml' || str_contains($contentType, 'xml');

        if (!$looksZip && !$looksGz && !$looksXml) {
            continue;
        }

        if ($name === '') {
            if ($looksZip) {
                $name = 'attachment.zip';
            } elseif ($looksGz) {
                $name = 'attachment.xml.gz';
            } else {
                $name = 'attachment.xml';
            }
        }

        $attachments[] = ['name' => $name, 'data' => $data, 'content_type' => $contentType];
    }

    return $attachments;
}

function parseEmlHeaders(string $block): array
{
    $lines = explode("\n", $block);
    $folded = [];
    $current = '';
    foreach ($lines as $line) {
        if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
            $current .= ' ' . ltrim($line);
        } else {
            if ($current !== '') {
                $folded[] = $current;
            }
            $current = $line;
        }
    }
    if ($current !== '') {
        $folded[] = $current;
    }

    $headers = [];
    foreach ($folded as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        $headers[$name] = $value;
    }
    return $headers;
}

function parseEmlPart(array $headers, string $body): array
{
    $parsed = parseContentTypeHeader((string)($headers['content-type'] ?? 'text/plain'));
    $type = strtolower($parsed['type']);

    if (str_starts_with($type, 'multipart/')) {
        $boundary = $parsed['params']['boundary'] ?? '';
        if ($boundary === '') {
            return [];
        }
        return splitMultipart($body, $boundary);
    }

    $name = '';
    $dispParsed = parseContentTypeHeader((string)($headers['content-disposition'] ?? ''));
    if (isset($dispParsed['params']['filename'])) {
        $name = decodeMimeHeaderValue($dispParsed['params']['filename']);
    } elseif (isset($parsed['params']['name'])) {
        $name = decodeMimeHeaderValue($parsed['params']['name']);
    }

    $encoding = strtolower((string)($headers['content-transfer-encoding'] ?? '7bit'));
    $data = decodeEmlContent($body, $encoding);

    return [[
        'name' => $name,
        'data' => $data,
        'content_type' => $type,
    ]];
}

function splitMultipart(string $body, string $boundary): array
{
    $delim = '--' . $boundary;
    $delimEnd = $delim . '--';
    $lines = explode("\n", $body);

    $results = [];
    $inPart = false;
    $buffer = [];

    foreach ($lines as $line) {
        $stripped = rtrim($line, "\r");
        if ($stripped === $delim) {
            if ($inPart) {
                $results[] = implode("\n", $buffer);
                $buffer = [];
            }
            $inPart = true;
            continue;
        }
        if ($stripped === $delimEnd) {
            if ($inPart) {
                $results[] = implode("\n", $buffer);
                $buffer = [];
            }
            $inPart = false;
            break;
        }
        if ($inPart) {
            $buffer[] = $line;
        }
    }

    if ($inPart && !empty($buffer)) {
        $results[] = implode("\n", $buffer);
    }

    $parsed = [];
    foreach ($results as $chunk) {
        $split = preg_split('/\n\n/', $chunk, 2);
        if (!is_array($split) || count($split) < 2) {
            continue;
        }
        [$headerBlock, $partBody] = $split;
        $partBody = rtrim($partBody, "\n");

        $headers = parseEmlHeaders($headerBlock);
        $subParts = parseEmlPart($headers, $partBody);
        foreach ($subParts as $p) {
            $parsed[] = $p;
        }
    }

    return $parsed;
}

function parseContentTypeHeader(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['type' => '', 'params' => []];
    }
    $parts = explode(';', $value);
    $type = trim((string)array_shift($parts));
    $params = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $eq = strpos($p, '=');
        if ($eq === false) {
            continue;
        }
        $key = strtolower(trim(substr($p, 0, $eq)));
        $val = trim(substr($p, $eq + 1));
        if (strlen($val) >= 2 && $val[0] === '"' && substr($val, -1) === '"') {
            $val = substr($val, 1, -1);
        }
        $params[$key] = $val;
    }
    if (isset($params['filename*']) && !isset($params['filename'])) {
        $encoded = $params['filename*'];
        if (preg_match("/^([^']*)'([^']*)'(.*)$/", $encoded, $m)) {
            $params['filename'] = rawurldecode($m[3]);
        } else {
            $params['filename'] = rawurldecode($encoded);
        }
    }
    if (isset($params['name*']) && !isset($params['name'])) {
        $encoded = $params['name*'];
        if (preg_match("/^([^']*)'([^']*)'(.*)$/", $encoded, $m)) {
            $params['name'] = rawurldecode($m[3]);
        } else {
            $params['name'] = rawurldecode($encoded);
        }
    }
    return ['type' => $type, 'params' => $params];
}

function decodeEmlContent(string $body, string $encoding): string
{
    switch ($encoding) {
        case 'base64':
            $clean = preg_replace('/\s+/', '', $body);
            $decoded = base64_decode((string)$clean, true);
            return is_string($decoded) ? $decoded : '';
        case 'quoted-printable':
            return quoted_printable_decode($body);
        default:
            return $body;
    }
}

function decodeMimeHeaderValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_decode_mimeheader') && str_contains($value, '=?')) {
        $decoded = @mb_decode_mimeheader($value);
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
    }
    return $value;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isDir()) {
            @rmdir($fileInfo->getPathname());
        } else {
            @unlink($fileInfo->getPathname());
        }
    }

    @rmdir($dir);
}
