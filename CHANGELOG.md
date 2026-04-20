# Changelog

All notable changes to `ottosmops/ocfl` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
