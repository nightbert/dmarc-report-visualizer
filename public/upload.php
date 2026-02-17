<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';

$inboxDir = resolveDataPath('INBOX_DIR', '/data/inbox', 'inbox');
$reportsDir = resolveDataPath('REPORTS_DIR', '/data/reports', 'reports');
$statusFile = resolveDataPath('STATUS_FILE', '/data/status.json', 'status.json');
$existingFingerprints = buildExistingFingerprints($reportsDir);

header('Content-Type: application/json; charset=UTF-8');

if (!is_dir($inboxDir) && !@mkdir($inboxDir, 0775, true) && !is_dir($inboxDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to prepare inbox directory']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['files'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No files uploaded']);
    exit;
}

$files = $_FILES['files'];
$results = [];

for ($i = 0; $i < count($files['name']); $i++) {
    $name = $files['name'][$i] ?? '';
    $tmp = $files['tmp_name'][$i] ?? '';
    $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;

    if ($error !== UPLOAD_ERR_OK) {
        $message = uploadErrorMessage($error);
        $results[] = ['name' => $name, 'status' => 'error', 'message' => $message];
        if ($name !== '') {
            $safeName = basename($name);
            updateStatus($statusFile, $safeName, 'error', 100, $message);
        }
        continue;
    }

    $safeName = basename($name);
    $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    $isXmlGz = str_ends_with(strtolower($safeName), '.xml.gz');

    if (!in_array($ext, ['zip', 'xml', 'gz'], true) || ($ext === 'gz' && !$isXmlGz)) {
        $results[] = ['name' => $safeName, 'status' => 'rejected', 'message' => 'Only ZIP/XML/XML.GZ allowed'];
        updateStatus($statusFile, $safeName, 'ignored', 100, 'Unsupported file type.');
        @unlink($tmp);
        continue;
    }

    $dest = $inboxDir . DIRECTORY_SEPARATOR . $safeName;
    if (file_exists($dest)) {
        $results[] = ['name' => $safeName, 'status' => 'duplicate', 'message' => 'Already in inbox'];
        updateStatus($statusFile, $safeName, 'duplicate', 100, 'Already in inbox.');
        @unlink($tmp);
        continue;
    }
    if ($reportsDir !== '' && is_dir($reportsDir)) {
        if (reportHasFile($reportsDir, $safeName)) {
            $results[] = ['name' => $safeName, 'status' => 'duplicate', 'message' => 'Already processed'];
            updateStatus($statusFile, $safeName, 'duplicate', 100, 'Already processed.');
            @unlink($tmp);
            continue;
        }
        $fingerprints = extractReportFingerprintsFromFile($tmp, $safeName);
        if (!empty($fingerprints) && allFingerprintsKnown($fingerprints, $existingFingerprints)) {
            $results[] = ['name' => $safeName, 'status' => 'duplicate', 'message' => 'Already processed'];
            updateStatus($statusFile, $safeName, 'duplicate', 100, 'Already processed.');
            @unlink($tmp);
            continue;
        }
    }

    if (@move_uploaded_file($tmp, $dest)) {
        $results[] = ['name' => basename($dest), 'status' => 'ok', 'message' => 'Uploaded'];
        updateStatus($statusFile, basename($dest), 'queued', 5, 'Queued for processing.');
    } else {
        $results[] = ['name' => $safeName, 'status' => 'error', 'message' => 'Failed to move'];
        updateStatus($statusFile, $safeName, 'error', 100, 'Failed to move uploaded file.');
        @unlink($tmp);
    }
}

echo json_encode(['results' => $results]);

if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
}

triggerIngest();


function triggerIngest(): void
{
    $ingestScript = realpath(__DIR__ . '/../bin/ingest.php');
    if ($ingestScript === false) {
        return;
    }

    if (runIngestViaExec($ingestScript)) {
        return;
    }

    $inlineIngest = __DIR__ . '/../bin/ingest-inline.php';
    if (is_file($inlineIngest)) {
        require $inlineIngest;
        return;
    }

    error_log('Upload ingest fallback missing: ' . $inlineIngest);
}

function runIngestViaExec(string $ingestScript): bool
{
    if (!isExecAvailable()) {
        return false;
    }

    $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = sprintf('%s %s 2>&1', escapeshellcmd($phpBinary), escapeshellarg($ingestScript));
    $output = [];
    $code = 1;

    @exec($cmd, $output, $code);
    if ($code === 0) {
        return true;
    }

    $logTail = '';
    if (!empty($output)) {
        $logTail = ' | output: ' . implode("\n", array_slice($output, -6));
    }
    error_log('Upload ingest exec failed with code ' . $code . $logTail);
    return false;
}

function isExecAvailable(): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    $disabled = strtolower((string)ini_get('disable_functions'));
    if ($disabled === '') {
        return true;
    }

    $disabledFunctions = array_map('trim', explode(',', $disabled));
    return !in_array('exec', $disabledFunctions, true);
}

function reportHasFile(string $reportsDir, string $name): bool
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($reportsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        if ($fileInfo->getBasename() === $name) {
            return true;
        }
    }
    return false;
}

function buildExistingFingerprints(string $reportsDir): array
{
    $fingerprints = [];
    if ($reportsDir === '' || !is_dir($reportsDir)) {
        return $fingerprints;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($reportsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        if (strtolower($fileInfo->getExtension()) !== 'xml') {
            continue;
        }
        $content = @file_get_contents($fileInfo->getPathname());
        if ($content === false) {
            continue;
        }
        $fingerprint = reportFingerprintFromXml($content);
        if ($fingerprint !== '') {
            $fingerprints[$fingerprint] = true;
        }
    }

    return $fingerprints;
}

function extractReportFingerprintsFromFile(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $isXmlGz = str_ends_with(strtolower($name), '.xml.gz');
    $fingerprints = [];

    if ($ext === 'xml') {
        $content = @file_get_contents($path);
        $fingerprint = $content !== false ? reportFingerprintFromXml($content) : '';
        if ($fingerprint !== '') {
            $fingerprints[] = $fingerprint;
        }
        return $fingerprints;
    }

    if ($ext === 'gz' && $isXmlGz) {
        $content = readGzContent($path);
        $fingerprint = $content !== '' ? reportFingerprintFromXml($content) : '';
        if ($fingerprint !== '') {
            $fingerprints[] = $fingerprint;
        }
        return $fingerprints;
    }

    if ($ext === 'zip') {
        if (!class_exists('ZipArchive')) {
            return [];
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $entryName = $stat['name'] ?? '';
            if ($entryName === '' || strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) !== 'xml') {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            $fingerprint = reportFingerprintFromXml($content);
            if ($fingerprint !== '') {
                $fingerprints[] = $fingerprint;
            }
        }
        $zip->close();
    }

    return array_values(array_unique($fingerprints));
}

function allFingerprintsKnown(array $fingerprints, array $known): bool
{
    if (empty($fingerprints)) {
        return false;
    }
    foreach ($fingerprints as $fingerprint) {
        if (!isset($known[$fingerprint])) {
            return false;
        }
    }
    return true;
}

function reportFingerprintFromXml(string $content): string
{
    $xml = loadXml($content);
    if ($xml === null) {
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

function readGzContent(string $path): string
{
    $magic = readFileMagic($path, 64);
    if ($magic !== '' && str_starts_with($magic, "PK\x03\x04")) {
        return readXmlFromZip($path);
    }
    if ($magic !== '' && isLikelyXml($magic)) {
        $content = @file_get_contents($path);
        return is_string($content) ? $content : '';
    }

    if (function_exists('gzopen')) {
        $in = @gzopen($path, 'rb');
        if ($in !== false) {
            $content = '';
            while (!gzeof($in)) {
                $chunk = gzread($in, 8192);
                if ($chunk === false) {
                    break;
                }
                $content .= $chunk;
            }
            gzclose($in);
            return $content;
        }
    }

    $cmd = 'gzip -dc ' . escapeshellarg($path);
    $output = @shell_exec($cmd);
    return is_string($output) ? $output : '';
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

function readXmlFromZip(string $path): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $content = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            continue;
        }
        $entryName = $stat['name'] ?? '';
        if ($entryName === '' || strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) !== 'xml') {
            continue;
        }
        $data = $zip->getFromIndex($i);
        if ($data !== false) {
            $content = $data;
            break;
        }
    }
    $zip->close();
    return $content;
}

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'Upload failed: file too large (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE => 'Upload failed: file too large (form limit).',
        UPLOAD_ERR_PARTIAL => 'Upload failed: partial upload.',
        UPLOAD_ERR_NO_FILE => 'Upload failed: no file received.',
        UPLOAD_ERR_NO_TMP_DIR => 'Upload failed: missing temp directory.',
        UPLOAD_ERR_CANT_WRITE => 'Upload failed: failed to write to disk.',
        UPLOAD_ERR_EXTENSION => 'Upload failed: blocked by PHP extension.',
        default => 'Upload failed: unknown error.',
    };
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
