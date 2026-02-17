#!/usr/bin/env bash
set -euo pipefail

mkdir -p /data/inbox /data/reports
if id -u www-data >/dev/null 2>&1; then
  chown -R www-data:www-data /data/inbox /data/reports
fi
chmod -R 0775 /data/inbox /data/reports
touch /data/status.json
if id -u www-data >/dev/null 2>&1; then
  chown www-data:www-data /data/status.json
fi
chmod 0664 /data/status.json

php /var/www/html/bin/ingest.php || true
/var/www/html/bin/worker.sh &

if command -v apache2-foreground >/dev/null 2>&1; then
  exec apache2-foreground
fi

exec php -S 0.0.0.0:80 -t /var/www/html/public
