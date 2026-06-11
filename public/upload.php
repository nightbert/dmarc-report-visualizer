<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';
require_once __DIR__ . '/_lib.php';

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
    $isEmail = in_array($ext, ['eml', 'msg'], true);

    if (!in_array($ext, ['zip', 'xml', 'gz', 'eml', 'msg'], true) || ($ext === 'gz' && !$isXmlGz)) {
        $results[] = ['name' => $safeName, 'status' => 'rejected', 'message' => 'Only ZIP/XML/XML.GZ/EML/MSG allowed'];
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
    if (!$isEmail && $reportsDir !== '' && is_dir($reportsDir)) {
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

// Run the ingest pipeline (via exec, with an inline fallback).
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

// Run the ingest script in a separate PHP process.
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

// Whether exec() is available and not disabled.
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

// Whether a report file with this name already exists.
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

// All known report fingerprints (index or file scan).
function buildExistingFingerprints(string $reportsDir): array
{
    $fingerprints = [];
    if ($reportsDir === '' || !is_dir($reportsDir)) {
        return $fingerprints;
    }

    $db = reportIndexOpen($reportsDir);
    if ($db !== null) {
        try {
            if (!reportIndexIsEmpty($db) || !reportIndexDirHasXml($reportsDir)) {
                return reportIndexAllFingerprints($db);
            }
        } catch (Throwable $e) {
            error_log('report index lookup failed, falling back to file scan: ' . $e->getMessage());
        }
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
        $fingerprint = reportFingerprintFromContent($content);
        if ($fingerprint !== '') {
            $fingerprints[$fingerprint] = true;
        }
    }

    return $fingerprints;
}

// Fingerprints of the report(s) inside an uploaded file.
function extractReportFingerprintsFromFile(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $isXmlGz = str_ends_with(strtolower($name), '.xml.gz');
    $fingerprints = [];

    if ($ext === 'xml') {
        $content = @file_get_contents($path);
        $fingerprint = $content !== false ? reportFingerprintFromContent($content) : '';
        if ($fingerprint !== '') {
            $fingerprints[] = $fingerprint;
        }
        return $fingerprints;
    }

    if ($ext === 'gz' && $isXmlGz) {
        $content = readGzContent($path);
        $fingerprint = $content !== '' ? reportFingerprintFromContent($content) : '';
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
            $fingerprint = reportFingerprintFromContent($content);
            if ($fingerprint !== '') {
                $fingerprints[] = $fingerprint;
            }
        }
        $zip->close();
    }

    return array_values(array_unique($fingerprints));
}

// Whether every given fingerprint is already known.
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

// Compute a report's fingerprint from XML content.
function reportFingerprintFromContent(string $content): string
{
    $xml = loadXml($content);
    return $xml !== null ? reportFingerprintFromXml($xml) : '';
}

// Read XML content from an .xml.gz, handling ZIP and plain XML.
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

// Read the first N bytes of a file.
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

// Whether a buffer looks like the start of XML.
function isLikelyXml(string $buffer): bool
{
    $trimmed = ltrim($buffer);
    return str_starts_with($trimmed, '<') || str_starts_with($trimmed, '<?xml');
}

// Read the first XML entry's content from a ZIP.
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

// Human-readable message for a PHP upload error code.
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
