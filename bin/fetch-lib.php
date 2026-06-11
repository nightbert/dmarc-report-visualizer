<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';

// Path to the shared mailbox state file.
function mailboxStateFile(): string
{
    return resolveDataPath('MAILBOX_STATE_FILE', '/data/mailbox-state.json', 'mailbox-state.json');
}

// Master switch for the mailbox fetch feature.
function mailboxFetchEnabled(): bool
{
    return envFlag('MAILBOX_FETCH_ENABLED', false);
}

// Whether Microsoft 365 credentials are fully configured.
function m365Configured(): bool
{
    return trim((string)getenv('M365_TENANT_ID')) !== ''
        && trim((string)getenv('M365_CLIENT_ID')) !== ''
        && trim((string)getenv('M365_CLIENT_SECRET')) !== ''
        && trim((string)getenv('M365_MAILBOX')) !== '';
}

// Whether IMAP credentials are fully configured.
function imapConfigured(): bool
{
    return trim((string)getenv('IMAP_HOST')) !== ''
        && trim((string)getenv('IMAP_USERNAME')) !== ''
        && trim((string)getenv('IMAP_PASSWORD')) !== '';
}

// The active mailbox provider ('imap', 'm365', or '').
function mailboxProvider(): string
{
    if (!mailboxFetchEnabled()) {
        return '';
    }
    $explicit = strtolower(trim((string)getenv('MAILBOX_PROVIDER')));
    if ($explicit === 'm365' || $explicit === 'imap') {
        return $explicit;
    }
    if (imapConfigured()) {
        return 'imap';
    }
    if (m365Configured()) {
        return 'm365';
    }
    return '';
}

// Whether any mailbox provider is configured.
function mailboxConfigured(): bool
{
    return mailboxProvider() !== '';
}

// Human-readable label for the active mailbox.
function mailboxStatusName(string $provider): string
{
    if ($provider === 'imap') {
        $user = trim((string)getenv('IMAP_USERNAME'));
        return 'IMAP: ' . ($user !== '' ? $user : trim((string)getenv('IMAP_HOST')));
    }
    return 'M365: ' . trim((string)getenv('M365_MAILBOX'));
}

// Acquire the exclusive fetch lock, or null if already held.
function acquireFetchLock(string $lockFile)
{
    $fp = @fopen($lockFile, 'c');
    if ($fp === false) {
        return null;
    }
    @chmod($lockFile, 0666);
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}

// Persist the result of a fetch run to the state file.
function writeFetchState(string $stateFile, string $result, string $message, int $saved = 0, int $failed = 0): void
{
    $payload = [
        'last_fetch_at' => time(),
        'result' => $result,
        'message' => $message,
        'saved' => $saved,
        'failed' => $failed,
    ];
    @file_put_contents($stateFile, json_encode($payload, JSON_PRETTY_PRINT));
    @chmod($stateFile, 0666);
}

// Acquire the fetch lock and ensure the inbox dir exists (exits on failure).
function beginFetchRun(string $inboxDir, string $stateFile)
{
    $lock = acquireFetchLock($stateFile . '.lock');
    if ($lock === null) {
        fetchLog('Another mailbox fetch is already running; skipping.');
        exit(0);
    }
    if (!is_dir($inboxDir) && !@mkdir($inboxDir, 0775, true) && !is_dir($inboxDir)) {
        fetchLog("Could not prepare inbox directory: {$inboxDir}");
        writeFetchState($stateFile, 'error', 'Could not prepare inbox directory.');
        exit(1);
    }
    return $lock;
}

// Report a fetch run's outcome to the status feed and state file.
function finishFetch(string $statusFile, string $statusName, string $stateFile, int $saved, int $failed): void
{
    if ($failed > 0) {
        updateStatus($statusFile, $statusName, 'error', 100, "{$saved} fetched, {$failed} failed.");
        writeFetchState($stateFile, 'error', "{$saved} fetched, {$failed} failed.", $saved, $failed);
    } else {
        updateStatus($statusFile, $statusName, 'done', 100, "{$saved} message(s) fetched from mailbox.");
        writeFetchState($stateFile, 'ok', "{$saved} message(s) fetched.", $saved);
    }
}

// Read a boolean-ish environment variable.
function envFlag(string $key, bool $default = false): bool
{
    $raw = getenv($key);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
}

// Write a line to the fetch log on stderr.
function fetchLog(string $message): void
{
    fwrite(STDERR, '[fetch] ' . $message . "\n");
}

// Save a raw message as a uniquely named .eml in the inbox.
function storeEmlInInbox(string $inboxDir, string $mime, ?int $receivedTs = null, string $prefix = 'mail'): bool
{
    $received = ($receivedTs !== null && $receivedTs > 0) ? $receivedTs : time();
    $name = $prefix . '-' . date('Ymd-His', $received) . '-' . bin2hex(random_bytes(4)) . '.eml';

    $tmp = tempnam(sys_get_temp_dir(), 'mailfetch_');
    if ($tmp === false) {
        return false;
    }
    if (@file_put_contents($tmp, $mime) === false) {
        @unlink($tmp);
        return false;
    }

    $dest = $inboxDir . DIRECTORY_SEPARATOR . $name;
    if (!@rename($tmp, $dest)) {
        if (!@copy($tmp, $dest)) {
            @unlink($tmp);
            return false;
        }
        @unlink($tmp);
    }

    @chmod($dest, 0664);
    return true;
}
