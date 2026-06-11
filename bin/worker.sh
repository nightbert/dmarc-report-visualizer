#!/usr/bin/env bash
set -euo pipefail

interval="${SCAN_INTERVAL_SECONDS:-30}"
fetch_interval="${MAILBOX_POLL_INTERVAL_SECONDS:-0}"
last_fetch=0

fetch_enabled=0
case "$(printf '%s' "${MAILBOX_FETCH_ENABLED:-}" | tr '[:upper:]' '[:lower:]')" in
  1|true|yes|on) fetch_enabled=1 ;;
esac

while true; do
  if (( fetch_enabled == 1 )) && [[ -n "${M365_CLIENT_ID:-}" || -n "${IMAP_HOST:-}" ]] && (( fetch_interval > 0 )); then
    now=$(date +%s)
    if (( now - last_fetch >= fetch_interval )); then
      php /var/www/html/bin/fetch-mail.php || true
      last_fetch=$now
    fi
  fi
  php /var/www/html/bin/ingest.php || true
  sleep "$interval"
done
