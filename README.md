# DMARC Report Visualizer (PHP)

Small PHP container that ingests DMARC XML/ZIP/XML.GZ files from `/data/inbox` and stores reports under `/data/reports/YYYY/MM`. The web UI reads from `/data/reports`.

## Usage

Build and run with Docker Compose:

```bash
docker compose up --build
```

Drop ZIP, XML, or XML.GZ files into `./data/inbox`. The container will:

- Extract XML files from ZIP archives.
- Decompress XML.GZ files.
- Move XML files into `/data/reports/YYYY/MM` based on report date.
- Delete processed files and any non-zip/xml files in the inbox.

Open the UI at: `http://localhost:8080`

The web UI includes a right sidebar for uploading files to `/data/inbox` and a live fetch status list.

## Environment

- `INBOX_DIR` (default: `/data/inbox`)
- `REPORTS_DIR` (default: `/data/reports`)
- `STATUS_FILE` (default: `/data/status.json`)
- `SCAN_INTERVAL_SECONDS` (default: `30` when unset)
- `REPORT_RETENTION_MONTHS` (default: `0`, set to a positive number to enable auto-deletion)

You can set these in a local `.env`, e.g. `REPORT_RETENTION_MONTHS=12` to keep one year.
