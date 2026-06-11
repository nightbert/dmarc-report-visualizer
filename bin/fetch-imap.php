<?php

declare(strict_types=1);

require_once __DIR__ . '/../data_paths.php';
require_once __DIR__ . '/../public/_lib.php';
require_once __DIR__ . '/fetch-lib.php';

$host = trim((string)getenv('IMAP_HOST'));
$username = trim((string)getenv('IMAP_USERNAME'));
$password = (string)getenv('IMAP_PASSWORD');

if ($host === '' || $username === '' || $password === '') {
    if ($host !== '' || $username !== '' || $password !== '') {
        fetchLog('IMAP fetching is partially configured; set IMAP_HOST, IMAP_USERNAME, and IMAP_PASSWORD.');
    }
    exit(0);
}

$encryption = strtolower(trim((string)getenv('IMAP_ENCRYPTION')));
if (!in_array($encryption, ['ssl', 'tls', 'starttls', 'none'], true)) {
    $encryption = 'ssl';
}
$port = (int)(getenv('IMAP_PORT') ?: 0);
if ($port <= 0) {
    $port = $encryption === 'ssl' ? 993 : 143;
}
$folder = trim((string)getenv('IMAP_FOLDER'));
if ($folder === '') {
    $folder = 'INBOX';
}
$validateCert = envFlag('IMAP_VALIDATE_CERT', true);
$deleteAfterFetch = envFlag('IMAP_DELETE_AFTER_FETCH');
$fetchAll = envFlag('IMAP_FETCH_ALL');
$maxMessages = (int)(getenv('IMAP_MAX_MESSAGES') ?: 25);
$maxMessages = max(1, min(200, $maxMessages));

$inboxDir = resolveDataPath('INBOX_DIR', '/data/inbox', 'inbox');
$statusFile = resolveDataPath('STATUS_FILE', '/data/status.json', 'status.json');
$stateFile = mailboxStateFile();
$statusName = mailboxStatusName('imap');

$lockHandle = beginFetchRun($inboxDir, $stateFile);

try {
    $imap = new ImapClient($host, $port, $encryption, $validateCert);
    $imap->login($username, $password);
    $imap->select($folder);
} catch (Throwable $e) {
    updateStatus($statusFile, $statusName, 'error', 100, 'IMAP connection failed: ' . $e->getMessage());
    fetchLog('IMAP connection failed: ' . $e->getMessage());
    writeFetchState($stateFile, 'error', 'Connection failed: ' . $e->getMessage());
    exit(1);
}

try {
    $uids = $imap->uidSearch($fetchAll ? 'ALL' : 'UNSEEN');
} catch (Throwable $e) {
    updateStatus($statusFile, $statusName, 'error', 100, 'IMAP search failed: ' . $e->getMessage());
    fetchLog('IMAP search failed: ' . $e->getMessage());
    writeFetchState($stateFile, 'error', 'Search failed: ' . $e->getMessage());
    $imap->logout();
    exit(1);
}

if ($uids === []) {
    $imap->logout();
    writeFetchState($stateFile, 'ok', 'No new messages.');
    exit(0);
}

rsort($uids, SORT_NUMERIC);
$uids = array_slice($uids, 0, $maxMessages);

updateStatus($statusFile, $statusName, 'processing', 20, 'Fetching ' . count($uids) . ' message(s) from mailbox.');

$saved = 0;
$failed = 0;
$expunge = false;
foreach ($uids as $uid) {
    try {
        $mime = $imap->uidFetchBody((int)$uid);
    } catch (Throwable $e) {
        $failed++;
        fetchLog("IMAP fetch failed for UID {$uid}: " . $e->getMessage());
        continue;
    }

    if ($mime === null || $mime === '') {
        $failed++;
        fetchLog("Empty message body for UID {$uid}.");
        continue;
    }

    if (!storeEmlInInbox($inboxDir, $mime, null, 'imap')) {
        $failed++;
        fetchLog("Could not store EML for UID {$uid}.");
        continue;
    }
    $saved++;

    try {
        if ($deleteAfterFetch) {
            $imap->uidStoreFlags((int)$uid, '\\Deleted');
            $expunge = true;
        } else {
            $imap->uidStoreFlags((int)$uid, '\\Seen');
        }
    } catch (Throwable $e) {
        fetchLog("Could not update flags for UID {$uid}: " . $e->getMessage());
    }
}

if ($expunge) {
    try {
        $imap->expunge();
    } catch (Throwable $e) {
        fetchLog('IMAP expunge failed: ' . $e->getMessage());
    }
}

$imap->logout();

finishFetch($statusFile, $statusName, $stateFile, $saved, $failed);

class ImapClient
{

    private $sock;
    private int $tag = 0;

    // Connect to the IMAP server and negotiate TLS.
    public function __construct(string $host, int $port, string $encryption, bool $validateCert)
    {
        $useImplicitTls = ($encryption === 'ssl');
        $transport = $useImplicitTls ? 'ssl' : 'tcp';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $validateCert,
                'verify_peer_name' => $validateCert,
                'allow_self_signed' => !$validateCert,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            $transport . '://' . $host . ':' . $port,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($sock === false) {
            throw new RuntimeException($errstr !== '' ? $errstr : ('could not connect to ' . $host . ':' . $port));
        }
        $this->sock = $sock;
        stream_set_timeout($this->sock, 60);

        $greeting = fgets($this->sock);
        if ($greeting === false || stripos($greeting, '* OK') !== 0 && stripos($greeting, '* PREAUTH') !== 0) {
            throw new RuntimeException('unexpected IMAP greeting: ' . trim((string)$greeting));
        }

        if ($encryption === 'tls' || $encryption === 'starttls') {
            $this->command('STARTTLS');
            $crypto = stream_socket_enable_crypto(
                $this->sock,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if ($crypto !== true) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
        }
    }

    // Authenticate with username and password.
    public function login(string $username, string $password): void
    {
        $this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password));
    }

    // Select a mailbox folder.
    public function select(string $folder): void
    {
        $this->command('SELECT ' . $this->quote($folder));
    }

    // Search for message UIDs matching a criteria.
    public function uidSearch(string $criteria): array
    {
        $resp = $this->command('UID SEARCH ' . $criteria);
        $uids = [];
        if (preg_match('/^\*\s+SEARCH\b([^\r\n]*)/mi', $resp['raw'], $m)) {
            preg_match_all('/\d+/', $m[1], $nums);
            foreach ($nums[0] as $n) {
                $uids[] = (int)$n;
            }
        }
        return $uids;
    }

    // Fetch a message's raw body by UID.
    public function uidFetchBody(int $uid): ?string
    {
        $resp = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        if ($resp['literals'] === []) {
            return null;
        }

        usort($resp['literals'], static fn($a, $b) => strlen($b) <=> strlen($a));
        return $resp['literals'][0];
    }

    // Add a flag to a message by UID.
    public function uidStoreFlags(int $uid, string $flag): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS (' . $flag . ')');
    }

    // Permanently remove messages flagged as deleted.
    public function expunge(): void
    {
        $this->command('EXPUNGE');
    }

    // Log out and close the connection.
    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (Throwable $e) {

        }
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
    }

    // Send a tagged command and read its response.
    private function command(string $command): array
    {
        $this->tag++;
        $tag = sprintf('A%03d', $this->tag);
        $payload = $tag . ' ' . $command . "\r\n";
        if (@fwrite($this->sock, $payload) === false) {
            throw new RuntimeException('failed to send command');
        }
        return $this->readResponse($tag, $command);
    }

    // Read a server response, collecting literals, until the tagged status.
    private function readResponse(string $tag, string $command): array
    {
        $raw = '';
        $literals = [];
        while (true) {
            $line = fgets($this->sock);
            if ($line === false) {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('timed out waiting for response');
                }
                throw new RuntimeException('connection closed by server');
            }

            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $literals[] = $this->readBytes((int)$m[1]);
                $raw .= $line . end($literals);
                continue;
            }

            $raw .= $line;
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b(.*)$/i', $line, $m)) {
                $status = strtoupper($m[1]);
                if ($status !== 'OK') {
                    $verb = strtok($command, ' ');
                    throw new RuntimeException($verb . ' failed: ' . trim($m[2]));
                }
                return ['status' => $status, 'raw' => $raw, 'literals' => $literals];
            }
        }
    }

    // Read exactly N bytes from the socket.
    private function readBytes(int $length): string
    {
        $data = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($this->sock, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out']) || feof($this->sock)) {
                    break;
                }
                continue;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }

    // Quote a string as an IMAP literal argument.
    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
