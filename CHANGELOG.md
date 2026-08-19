# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-08-19

First release.

### Added

- `MyboxAdapter`, a Flysystem v3 `FilesystemAdapter` for Naver MYBOX built on
  `minhyung/mybox`. Passes Flysystem's adapter conformance suite.
- A directory-listing cache (`MemoryDirectoryCache`, `NullDirectoryCache`) that
  resolves paths to resource ids by walking with listing calls, never with the
  rate-limited search endpoint, and answers exists/size/last-modified/mime-type
  for a whole directory from one request.
- `TemporaryUrlGenerator` over MYBOX's single-use ten-minute download URLs.
- `MyboxAdapterOptions` for deletion mode (trash or purge), reported visibility,
  unknown-stream-length strategy, page size, cache TTL and size, and 423 retries.
- A root-directory prefix that confines the adapter to one folder.
- Case- and Unicode-folded name matching (`Path\NameKey`), because MYBOX matches
  names case-insensitively and stores Hangul composed while macOS decomposes it.
- `tools/probe-adapter.php` to measure undocumented MYBOX behaviour, and
  `tools/cleanup-sandbox.php` to clear test debris from an account. The measured
  answers are in `docs/adapter-notes.md`.

[Unreleased]: https://github.com/overworks/flysystem-mybox/compare/0.1.0...HEAD
[0.1.0]: https://github.com/overworks/flysystem-mybox/releases/tag/0.1.0
