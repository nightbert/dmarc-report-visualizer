#!/usr/bin/env bash
set -euo pipefail

mkdir -p /data/inbox /data/reports
chown -R www-data:www-data /data/inbox /data/reports
chmod -R 0775 /data/inbox /data/reports
touch /data/status.json
chown www-data:www-data /data/status.json
chmod 0664 /data/status.json

php /var/www/html/bin/ingest.php || true
/var/www/html/bin/worker.sh &

exec apache2-foreground
