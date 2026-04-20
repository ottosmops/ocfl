# ottosmops/ocfl

A PHP implementation of the [Oxford Common File Layout (OCFL) v1.1](https://ocfl.io/1.1/spec/).

> Status: **pre-alpha.** Phase 1 (digest + NAMASTE) complete. Not yet usable as a library.

## About OCFL

OCFL is an application-independent approach to storing digital content in a
structured, transparent, and predictable manner. Its goals are long-term
digital preservation, rebuildability of repositories from storage alone, and
storage-system portability (local FS, S3, Azure…).

## Requirements

- PHP 8.3 or higher
- ext-hash, ext-json, ext-mbstring

## Installation

```bash
composer require ottosmops/ocfl
```

## Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Foundation: Digest, NAMASTE, JSON I/O | in progress |
| 2 | Inventory parsing + validation | planned |
| 3 | Object read (version checkout) | planned |
| 4 | Object write (commit, forward-delta) | planned |
| 5 | Storage root + layouts | planned |
| 6 | Full validator against OCFL fixtures | planned |
| 7 | Flysystem adapters (S3, Azure) | planned |
| 8 | 1.0 release | planned |

## Development

```bash
composer install
composer check        # format + analyse + test
composer test         # pest
composer analyse      # phpstan (level max)
composer format       # laravel pint
```

Tests run against the [official OCFL fixtures](https://github.com/OCFL/fixtures)
included as a git submodule at `tests/fixtures/ocfl`.

```bash
git submodule update --init --recursive
```

## License

MIT © Andreas Kränzle — see [LICENSE](LICENSE).
