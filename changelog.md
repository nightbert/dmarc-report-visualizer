# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, adapted to the release history available in this repository.

## [Unreleased]

- No unreleased changes documented yet.

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

[Unreleased]: https://github.com/nightbert/dmarc-report-visualizer/compare/v1.1.0...HEAD
[v1.1.0]: https://github.com/nightbert/dmarc-report-visualizer/compare/v1.0.1...v1.1.0
[v1.0.1]: https://github.com/nightbert/dmarc-report-visualizer/compare/v1.0.0...v1.0.1
[v1.0.0]: https://github.com/nightbert/dmarc-report-visualizer/releases/tag/v1.0.0
