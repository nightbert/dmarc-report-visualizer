#!/usr/bin/env bash
set -euo pipefail

interval="${SCAN_INTERVAL_SECONDS:-30}"

while true; do
  php /var/www/html/bin/ingest.php || true
  sleep "$interval"
done
