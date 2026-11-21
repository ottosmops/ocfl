# Changelog

All notable changes to `ottosmops/ocfl` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- `StorageRoot::create()` now writes a spec-conformant `ocfl_layout.json` (OCFL 1.1 §4.1): exactly the keys `extension` and `description`. The layout's parameters go to `extensions/<extension>/config.json`, as the community extensions define. v1.0.0 wrote the layout configuration (with `extensionName`) straight into `ocfl_layout.json`, which other OCFL implementations do not recognise.
- `StorageRoot::open()` reads the spec form, taking the parameters from `extensions/<extension>/config.json` (extension defaults when the file is absent). A `config.json` that is not valid JSON or names a different extension is rejected with E070.

### Added

- `StorageRoot::hasLegacyLayoutDeclaration()`: true for a root written by v1.0.0. Such roots still open and resolve object paths unchanged; opening never rewrites them.
- `StorageRoot::upgradeLayoutDeclaration()`: rewrites a v1.0.0-style declaration in the spec form (config first, then `ocfl_layout.json`). Objects are not touched; no-op on a conformant root.

## [1.0.0] — 2026-04-20

### Added

- PHP implementation of the OCFL 1.1 storage specification.
- Read-side API: `OcflObject::open`, version traversal, logical-path
  resolution with forward-delta dedup, `readContent`, `checkout`.
- Write-side API: `OcflObject::create`, `VersionBuilder` fluent API
  (`addFile` / `addContents` / `removeFile` / `renameFile` /
  `withMessage` / `withUser` / `withCreated`), crash-safe commit.
- `StorageRoot` with pluggable `StorageLayout` interface.
  Ships `0002-flat-direct-storage-layout` and
  `0004-hashed-n-tuple-storage-layout`.
- `Filesystem` abstraction with `LocalFilesystem` (default) and
  `FlysystemFilesystem` (optional) for cloud backends — S3, Azure,
  GCS, any `league/flysystem` ^3 adapter.
- `ObjectValidator` covering all 55 OCFL bad-object fixtures, all 12
  good-object fixtures, and all 13 warn-object fixtures. Emits 43
  spec error codes and 10 spec warning codes.
- `ocfl` CLI binary: `validate`, `info`, `list`, `create`, `commit`,
  `checkout`; supports `--json` output and returns 0/1/2/3 exit codes.
- Test suite: 249 tests, 469 assertions, 94 % line coverage.
  Runs against the upstream [OCFL fixtures](https://github.com/OCFL/fixtures).

[Unreleased]: https://github.com/ottosmops/ocfl/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ottosmops/ocfl/releases/tag/v1.0.0
