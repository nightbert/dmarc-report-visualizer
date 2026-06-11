<?php

declare(strict_types=1);

require_once __DIR__ . '/fetch-lib.php';

$provider = mailboxProvider();
if ($provider === 'imap') {
    require __DIR__ . '/fetch-imap.php';
} elseif ($provider === 'm365') {
    require __DIR__ . '/fetch-m365.php';
}

exit(0);
