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
        processXml($path, $reportsDir, basename($path));
        updateStatus($statusFile, $entry, 'done', 100, 'XML stored.');
        continue;
    }

    if ($ext === 'gz') {
        updateStatus($statusFile, $entry, 'queued', 10, 'GZ queued.');
        processGz($path, $reportsDir, $statusFile);
        continue;
    }

    updateStatus($statusFile, $entry, 'ignored', 100, 'Not a ZIP/XML file.');
    @unlink($path);
}

$retentionMonths = reportRetentionMonths();
if ($retentionMonths > 0) {
    purgeOldReports($reportsDir, $retentionMonths);
}

function processZip(string $zipPath, string $reportsDir, string $statusFile): void
{
    $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dmarc_' . bin2hex(random_bytes(6));
    if (!mkdir($tmpDir, 0775, true)) {
        updateStatus($statusFile, basename($zipPath), 'error', 100, 'Failed to create temp directory.');
        @unlink($zipPath);
        return;
    }

    updateStatus($statusFile, basename($zipPath), 'extracting', 35, 'Extracting ZIP.');
    $cmd = 'unzip -qq ' . escapeshellarg($zipPath) . ' -d ' . escapeshellarg($tmpDir);
    exec($cmd, $output, $code);

    if ($code === 0) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir));
        updateStatus($statusFile, basename($zipPath), 'processing', 70, 'Processing XML inside ZIP.');
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $src = $fileInfo->getPathname();
            $base = $fileInfo->getBasename();
            $ext = strtolower($fileInfo->getExtension());
            if ($ext === 'xml') {
                processXml($src, $reportsDir, $base);
                continue;
            }
            if ($ext === 'gz' && str_ends_with(strtolower($base), '.xml.gz')) {
                processGz($src, $reportsDir, $statusFile);
            }
        }
        updateStatus($statusFile, basename($zipPath), 'done', 100, 'ZIP processed.');
    } else {
        updateStatus($statusFile, basename($zipPath), 'error', 100, 'Failed to extract ZIP.');
    }

    removeDir($tmpDir);
    @unlink($zipPath);
}

function processXml(string $xmlPath, string $reportsDir, string $preferredName): void
{
    $timestamp = extractReportTimestamp($xmlPath);
    $year = date('Y', $timestamp);
    $month = date('m', $timestamp);

    $destDir = $reportsDir . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }

    $name = sanitizeFileName($preferredName);
    if ($name === '') {
        $name = 'report.xml';
    }

    $destPath = $destDir . DIRECTORY_SEPARATOR . $name;
    $destPath = ensureUniquePath($destPath);

    if (!@rename($xmlPath, $destPath)) {
        if (@copy($xmlPath, $destPath)) {
            @unlink($xmlPath);
        }
    }

    @chmod($destPath, 0644);
}

function processGz(string $gzPath, string $reportsDir, string $statusFile): void
{
    $baseName = basename($gzPath);
    if (strtolower(substr($baseName, -7)) !== '.xml.gz') {
        updateStatus($statusFile, $baseName, 'ignored', 100, 'Not an XML.GZ file.');
        @unlink($gzPath);
        return;
    }

    $magic = readFileMagic($gzPath, 64);
    if ($magic !== '' && str_starts_with($magic, "PK\x03\x04")) {
        updateStatus($statusFile, $baseName, 'queued', 10, 'ZIP detected in XML.GZ.');
        processZip($gzPath, $reportsDir, $statusFile);
        return;
    }
    if ($magic !== '' && isLikelyXml($magic)) {
        updateStatus($statusFile, $baseName, 'processing', 40, 'XML detected in XML.GZ.');
        processXml($gzPath, $reportsDir, preg_replace('/\\.gz$/i', '', $baseName));
        @unlink($gzPath);
        updateStatus($statusFile, $baseName, 'done', 100, 'XML stored.');
        return;
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'dmarc_xml_');
    if ($tmpFile === false) {
        updateStatus($statusFile, $baseName, 'error', 100, 'Failed to create temp file.');
        @unlink($gzPath);
        return;
    }

    updateStatus($statusFile, $baseName, 'extracting', 40, 'Decompressing XML.GZ.');
    $decompressed = false;
    if (function_exists('gzopen')) {
        $in = @gzopen($gzPath, 'rb');
        if ($in !== false) {
            $out = @fopen($tmpFile, 'wb');
            if ($out === false) {
                updateStatus($statusFile, $baseName, 'error', 100, 'Failed to write temp file.');
                gzclose($in);
                @unlink($gzPath);
                @unlink($tmpFile);
                return;
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
            updateStatus($statusFile, $baseName, 'error', 100, 'Failed to open GZ.');
            @unlink($gzPath);
            @unlink($tmpFile);
            return;
        }
    }

    $preferredName = preg_replace('/\\.gz$/i', '', $baseName);
    updateStatus($statusFile, $baseName, 'processing', 80, 'Processing XML.');
    processXml($tmpFile, $reportsDir, $preferredName);

    @unlink($tmpFile);
    @unlink($gzPath);
    updateStatus($statusFile, $baseName, 'done', 100, 'XML.GZ processed.');
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
