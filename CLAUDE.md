# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A dependency-free PHP application (no Composer, no npm, no framework, no build step) that ingests DMARC aggregate reports and serves a web UI. See `README.md` for the user-facing feature list and the full environment-variable table.

## Commands

There is no test suite, linter, or build tooling. Verification means syntax checks plus running the real thing:

```bash
php -l public/_lib.php          # PHP syntax check
node --check public/js/index.js # JS syntax check
bash -n bin/worker.sh
docker compose up -d --build    # run the app on :8090 (the image bakes the code in — a rebuild is required to deploy changes)
```

The host PHP CLI lacks `simplexml`, `pdo_sqlite` and `sqlite3`, so it can only run `php -l`. To actually exercise the code, mount the working tree into the stock PHP image (which has both extensions) instead of rebuilding the project image:

```bash
# run a script against the real reports in ./data/reports
docker run --rm -v "$PWD:/app" -w /app -e REPORTS_DIR=/app/data/reports \
  -e REPORT_INDEX_FILE=/tmp/idx.sqlite php:8.2-cli-alpine php /app/<script>.php

# or serve the UI on :8099
docker run --rm -p 8099:8000 -v "$PWD:/app" -w /app -e REPORTS_DIR=/app/data/reports \
  -e REPORT_INDEX_FILE=/tmp/idx.sqlite php:8.2-cli-alpine php -S 0.0.0.0:8000 -t /app/public
```

`REPORT_INDEX_FILE` redirects the SQLite index away from the real one, so throwaway runs never touch `data/reports/.report-index.sqlite`. For frontend logic, `npm install jsdom` in a scratch directory works for headless DOM tests.

The running project container has the PHP sqlite extensions but no `sqlite3` CLI — query the index with `docker compose exec -T dmarc-report-visualizer php -r '...PDO...'`.

## Architecture

**Two processes, one container.** `bin/entrypoint.sh` runs one ingest pass, backgrounds `bin/worker.sh` (loop: optional mailbox fetch → `bin/ingest.php` → sleep `SCAN_INTERVAL_SECONDS`), then execs PHP's built-in server on `public/`. Both halves share `public/_lib.php` and `report_index.php` — `bin/ingest.php` requires the web library, not the other way around.

**The XML files are the source of truth; the SQLite index is a disposable cache.** `report_index.php` holds a `reports` table (one row per file, keyed by relative path) and a `report_records` table (one row per source-IP record, powering Trends). `reportIndexSync()` reconciles the DB against the files on disk each ingest run, so deleting the index or editing files by hand is safe. Bumping `REPORT_INDEX_DATA_VERSION` wipes both tables and forces a full re-parse — do that whenever the indexed columns change.

**Every read path degrades when `pdo_sqlite` is missing.** `reportSummariesPage()` queries the index and falls back to `reportSummariesPageFromScan()`, which parses every XML file instead. When you change listing behavior (filters, sorting, pagination), change both paths and confirm they agree — a mismatch is silent, since it only shows on hosts without the extension. Trends and the sender drilldown have no fallback and report `available: false`.

**Path resolution** goes through `data_paths.php`: `resolveDataPath()` prefers the env override, then the `/data/...` system path, then the repo-local `./data/...` fallback if `/data` is not writable. Never hardcode either location.

**Ingest pipeline** (`bin/ingest.php`) dispatches on file extension to `processZip`/`processXml`/`processGz`/`processEml`/`processMsg`, which recurse into each other (a ZIP holds GZs, an EML holds ZIPs). Deduplication uses a content fingerprint (report id + domain + date range, empty if any part is missing) checked against the index, falling back to a file scan. Stored reports land in `<reports>/YYYY/MM/`. `.msg` needs the external `msgconvert`; `.eml` prefers the `mailparse` extension and falls back to a hand-written MIME parser.

**Status feed.** Ingest writes progress into `status.json` via `updateStatus()` (flock + a monotonically increasing `sequence`). The dashboard polls `status.php` every 3s and discards responses with an older sequence, so concurrent writers can't reorder the UI. When the set of `done` items changes, the frontend reloads the report listing.

**Uploads run ingest synchronously.** `public/upload.php` execs `bin/ingest.php` in a separate process; if `exec()` is disabled it `require`s `bin/ingest-inline.php`, which wraps the same script in a namespace so its function definitions don't collide with the already-loaded library.

**Web layer:** no router — one PHP file per page (`index.php`, `report.php`, `trends.php`, `sender.php`) plus JSON endpoints (`reports.php`, `trends-data.php`, `status.php`, `upload.php`, `delete-report.php`, `fetch-mailbox.php`, `update-check.php`). `_layout.php` emits head/hero/footer; `_lib.php` is the shared library of plain global functions.

Report URLs carry an opaque token: `buildFileToken()` base64-encodes the path relative to the reports root, and `resolveFileToken()` rejects `..` and anything resolving outside the root. Always go through those two.

**Frontend:** plain ES5-ish JS, one file per page under `public/js`, loaded with `defer`. `chart.js` is a hand-rolled SVG chart shared by Trends and the sender drilldown; `sort-table.js` adds click-to-sort to any `table[data-sortable]` (cells supply raw sort keys via `data-sort`, `data-nosort` opts a column out). The dashboard listing is the exception — it is paginated server-side, so it sorts server-side too (`sort`/`dir` params → SQL `ORDER BY` whitelist in `reportIndexSortColumns()`). CSS is split by concern and pulled together by `style.css`; colors and the `--space-*` scale live as custom properties in `base.css`.

## DMARC parsing rules

Reporters disagree about the schema, so parsing is deliberately defensive:

- **Always use `local-name()` XPath.** Some reports declare `urn:ietf:params:xml:ns:dmarc-2.0` (DMARCbis), others declare no namespace at all. Namespace-bound XPath silently matches nothing.
- **Treat every field as optional.** `envelope_to`, `pct`, `fo`, `discovery_method`, whole `auth_results` blocks — all appear in some reports and not others. `report.php` infers which record columns to render from what is actually present; when adding a column, check presence across *all* records, not just the first.
- `loadXml()` strips a BOM and control characters before parsing because malformed reports are common. Metadata has layered fallbacks (`dmarcReportDomain()`, `dmarcReportOrg()`) that derive org/domain from the report id or the metadata email when the fields are missing.

## Conventions

- `declare(strict_types=1)` in every PHP file; global functions only, no classes or autoloader.
- One short `//` sentence above each function saying what it does — match this, don't add block comments or docblocks.
- Escape all output with `htmlspecialchars($v, ENT_QUOTES)`; the JS renderers have their own `escapeHtml`.
- Code, comments, and UI strings are English.
- Releases: bump `$APP_VERSION` in `public/_lib.php`, add a `changelog.md` entry, then tag `vX.Y.Z` — the tag triggers the multi-arch GHCR publish in `.github/workflows/docker-publish.yml`.
