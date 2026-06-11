# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, adapted to the release history available in this repository.

## [v1.2.0] - 2026-06-11

### Added

- Added a **Trends** view (linked from the dashboard header, also at `/trends.php`) that aggregates the stored reports: a per-day column chart of message volume split by DMARC alignment (full / DKIM only / SPF only / fail), headline totals (messages, pass rate, passing/failing counts, distinct sources and domains), and a top-senders table ranked by message volume with each source's pass/fail split. The view reuses the dashboard's year / month / organization filters and is backed by the `trends-data.php` JSON endpoint.
- Added a **sender drilldown** (`/sender.php?ip=...`, linked from each IP in the top-senders table) showing a single source IP's alignment over time, a per-domain breakdown, and the stored reports it appears in.
- Added a record-level table to the SQLite index (one row per source-IP line), with a data-version migration that clears and rebuilds the index from the XML files when the cached schema changes. The Trends and drilldown views run entirely offline from the stored reports — no external services or network lookups.
- Added optional Microsoft 365 mailbox fetching via Microsoft Graph: the worker polls a configured mailbox, downloads report emails as `.eml` files into the inbox, and marks them as read or deletes them depending on `M365_DELETE_AFTER_FETCH`.
- Added the environment variables `M365_TENANT_ID`, `M365_CLIENT_ID`, `M365_CLIENT_SECRET`, `M365_MAILBOX`, `M365_FOLDER`, `M365_DELETE_AFTER_FETCH`, and `M365_MAX_MESSAGES`.

- Added a **Fetch mailbox** button to the dashboard sidebar for triggering a Microsoft 365 fetch on demand, plus a display of the last fetch date and time. Setting `MAILBOX_POLL_INTERVAL_SECONDS=0` disables automatic polling so the mailbox is only fetched manually.
- Added pending files in the inbox directory to the live fetch status feed.
- Added a lock so manual and scheduled mailbox fetches cannot run concurrently, and a state file (`MAILBOX_STATE_FILE`, default `/data/mailbox-state.json`) that records the last fetch result.
- Added a SQLite metadata index (`.report-index.sqlite` in the reports directory, override with `REPORT_INDEX_FILE`) that caches report fingerprints and summary metadata. Duplicate detection and the dashboard listing now use index lookups instead of re-parsing every stored XML file; the index rebuilds itself automatically from the XML files, which remain the source of truth.
- Added IMAP as an alternative mailbox provider alongside Microsoft 365, implemented as a dependency-free PHP IMAP client (no PHP IMAP extension required). Supports SSL, STARTTLS, and plain connections, optional certificate validation, fetching only unread or all messages, and marking fetched messages as read or deleting them. The new `MAILBOX_PROVIDER` setting (`auto`/`m365`/`imap`) selects the provider, and the environment variables `IMAP_HOST`, `IMAP_USERNAME`, `IMAP_PASSWORD`, `IMAP_PORT`, `IMAP_ENCRYPTION`, `IMAP_VALIDATE_CERT`, `IMAP_FOLDER`, `IMAP_DELETE_AFTER_FETCH`, `IMAP_FETCH_ALL`, and `IMAP_MAX_MESSAGES` configure it.

### Changed

- Reduced a full index rebuild to a single read and parse per file (previously the summary, fingerprint, and record details were each parsed separately), so rebuilds are faster despite the new record-level data.
- Split the report listing's "Date range" column into separate "Start" and "End" columns (dashboard and sender drilldown).
- Stopped wrapping report table cells; long values now keep to one line. The Report ID column is truncated with an ellipsis (full value on hover) so the listing fits without horizontal scrolling, and the tables still scroll horizontally as a fallback.
- Let the dashboard report table fill the column height so its bottom edge lines up with the sidebar instead of ending short.
- Gave the Trends view the same two-column layout as the dashboard, moving its year / month / organization filters into a sidebar card.
- Centralized the stylesheet's colors and spacing as CSS custom properties: all colors are now defined once in `base.css` (with semantic names that alias shared values), and margins, paddings and gaps draw from a single `--space-*` spacing scale. No visual change.
- Consolidated repeated CSS declarations into a shared `components.css` (card surface, uppercase captions, custom `<details>` markers) so common patterns live in one rule instead of being copied per component.
- Extracted the duplicated page chrome (document head, hero header with navigation, and footer) into `_layout.php`, rendered by the dashboard, report, trends and sender pages instead of each repeating the markup.
- Removed duplicated PHP helpers: `upload.php` now reuses the shared library (`_lib.php`) instead of carrying its own byte-identical copies of the XML, fingerprint and status-feed helpers, and the IMAP and Microsoft 365 fetchers share a common fetch lifecycle (`beginFetchRun` / `finishFetch`) in `fetch-lib.php`.
- Moved the shared status feed helpers and the report fingerprint helper from the ingest script into the common library.
- Made the report listing scale to large collections: the dashboard now paginates and filters on the server via the SQLite index, loading only one page (20 rows) per request instead of rendering every stored report. Web requests no longer walk the whole reports directory on each load — they trust the index and only reconcile it periodically (the worker keeps it up to date), so page load time stays roughly constant as the number of reports grows.
- Deleting a report through the UI now removes its index row immediately.
- Generalized mailbox fetching across providers: the manual fetch button, automatic polling (`MAILBOX_POLL_INTERVAL_SECONDS`), the shared fetch lock, and the last-fetch state file now apply to both Microsoft 365 and IMAP. The state file can be set with `MAILBOX_STATE_FILE`.
- Disabled the mailbox fetch feature by default behind a new `MAILBOX_FETCH_ENABLED` master switch (default `false`). While it is off, no mailbox is polled, the **Fetch mailbox** button is hidden from the dashboard, and the fetch endpoint is inactive — even when provider credentials are configured. Set `MAILBOX_FETCH_ENABLED=true` to enable it.
- Switched the Docker image to a single Alpine-based build serving the UI through PHP's built-in web server, cutting the image from ~505 MB to ~160 MB. The `mailparse` extension and the Email::Outlook::Message tooling are still included, so EML/MSG parsing is unchanged. The previous Apache-based target and the `DOCKER_TARGET` switch were removed.

### Removed

- Removed the `runtime-apache` Docker build target and the `DOCKER_TARGET` environment variable; the Alpine build is now the only image.

### Fixed

- Fixed ingestion of `.msg` files that are not real Outlook OLE2 documents (for example MIME messages saved with a `.msg` extension, which previously failed with `msgconvert ... Parsing as OLE file failed`). Such files are now detected via the OLE2 signature and routed through the regular email pipeline instead of being rejected.

## [v1.1.1] - 2026-05-21

### Fixed

- Fixed DMARC date range parsing for reports that provide Unix timestamps in milliseconds.
- Fixed parsing of malformed DMARC report metadata with placeholder organization names or missing policy domains.

## [v1.1.0] - 2026-04-14

### Added

- Added support for ingesting `.eml` and `.msg` email files and extracting DMARC report attachments from them.
- Added duplicate detection for DMARC XML reports based on report metadata to avoid storing the same report multiple times.
- Added an error-only filter for the live fetch status list in the web UI.
- Added a project changelog and linked the README to the documented release history.

### Changed

- Extended the Docker images with the dependencies needed for email parsing and MSG conversion.
- Updated the upload UI and server-side validation to accept `XML`, `XML.GZ`, `ZIP`, `EML`, and `MSG` files.
- Improved the dashboard layout so the sidebar status area behaves better on large and small screens.
- Refreshed the README with a fuller feature overview, current startup instructions, and data path details.

### Fixed

- Fixed status handling for nested ZIP and GZ processing so duplicate and error outcomes are reported correctly.
- Fixed upload-time duplicate checks so email containers are parsed before deciding whether a report already exists.

## [v1.0.1] - 2026-02-17

### Added

- Added a smaller `runtime-alpine` Docker build target as an alternative to the default Apache-based image.
- Added `bin/ingest-inline.php` and `public/reports.php` to support the updated upload and refresh flow.
- Added repository and version links in the web UI footer.

### Changed

- Improved the upload workflow to process files in batches and reduce request size issues.
- Expanded the README with the smaller runtime option and current usage details.
- Refined report and status polling in the web UI.

### Fixed

- Fixed status and report refresh behavior after uploads and background processing.

## [v1.0.0] - 2026-01-28

### Added

- Added browser-based file uploads for DMARC reports.

### Changed

- Changed the Docker Compose startup command to run in detached mode.
- Updated the documented local UI URL to `http://localhost:8080` at that time.
- Refreshed the README.

[v1.2.0]: https://github.com/nightbert/dmarc-report-visualizer/compare/v1.1.1...v1.2.0
[v1.1.1]: https://github.com/nightbert/dmarc-report-visualizer/compare/v1.1.0...v1.1.1
[v1.1.0]: https://github.com/nightbert/dmarc-report-visualizer/compare/v1.0.1...v1.1.0
[v1.0.1]: https://github.com/nightbert/dmarc-report-visualizer/compare/v1.0.0...v1.0.1
[v1.0.0]: https://github.com/nightbert/dmarc-report-visualizer/releases/tag/v1.0.0
