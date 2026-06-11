#!/usr/bin/env bash
set -euo pipefail

mkdir -p /data/inbox /data/reports
touch /data/status.json /data/mailbox-state.json /data/mailbox-state.json.lock
if id -u www-data >/dev/null 2>&1; then
  chown www-data:www-data /data /data/inbox /data/reports /data/status.json /data/mailbox-state.json /data/mailbox-state.json.lock
  chown -R www-data:www-data /data/inbox /data/reports
fi
chmod 0775 /data
chmod -R 0775 /data/inbox /data/reports
chmod 0664 /data/status.json
chmod 0666 /data/mailbox-state.json /data/mailbox-state.json.lock

php /var/www/html/bin/ingest.php || true
/var/www/html/bin/worker.sh &

if command -v apache2-foreground >/dev/null 2>&1; then
  exec apache2-foreground
fi

exec php -S 0.0.0.0:80 -t /var/www/html/public
