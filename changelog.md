# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, adapted to the release history available in this repository.

## [Unreleased]

- No unreleased changes documented yet.

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

[Unreleased]: https://github.com/nightbert/dmarc-report-visualizer/compare/v1.0.1...HEAD
[v1.0.1]: https://github.com/nightbert/dmarc-report-visualizer/compare/v1.0.0...v1.0.1
[v1.0.0]: https://github.com/nightbert/dmarc-report-visualizer/releases/tag/v1.0.0
