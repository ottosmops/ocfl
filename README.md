# ottosmops/ocfl

[![Tests](https://github.com/ottosmops/ocfl/actions/workflows/tests.yml/badge.svg)](https://github.com/ottosmops/ocfl/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

`ottosmops/ocfl` is a PHP library for working with the [Oxford Common File
Layout (OCFL) v1.1](https://ocfl.io/1.1/spec/) — a storage specification for
long-term digital preservation.

It reads, writes, and validates OCFL objects against the local filesystem or
any [Flysystem](https://flysystem.thephpleague.com) v3 backend (S3, Azure,
GCS, in-memory, …).

## Features

- **Domain API** — readonly value objects for `Inventory`, `Version`, `User`,
  `OcflObject`, `StorageRoot` — the whole spec modelled in types.
- **Read** — open an existing object, list versions, resolve logical paths to
  content paths (respecting forward-delta dedup), stream content, or check
  out an entire version to a directory.
- **Write** — create an object, commit new versions with content
  `addFile` / `addContents`, `renameFile`, `removeFile`. Forward-delta dedup
  and crash-safe staging (`.tmp-XXXX` → `rename`) handled automatically.
- **Storage layouts** — `0002-flat-direct-storage-layout` and
  `0004-hashed-n-tuple-storage-layout` out of the box, with a pluggable
  `StorageLayout` interface for custom extensions.
- **Validator** — rejects all 55 OCFL [bad-object fixtures](https://github.com/OCFL/fixtures)
  with the correct spec-referenced error codes, accepts all 12 good-object
  fixtures, and emits 13/13 warn-object advisories.
- **Pluggable storage** — `LocalFilesystem` by default; `FlysystemFilesystem`
  adapter for any `league/flysystem` v3 backend (S3, Azure, GCS, …).
- **Framework-agnostic** — zero required Composer runtime dependencies
  beyond the PHP standard library. Laravel / Symfony wrappers are easy to
  build on top.

## Requirements

- PHP 8.3 or later
- `ext-hash`, `ext-json`, `ext-mbstring`

Optional, for cloud storage:

- `league/flysystem` ^3.0 plus the adapter of your choice

## Installation

```bash
composer require ottosmops/ocfl
```

Tests run against the official [OCFL fixtures](https://github.com/OCFL/fixtures).
Clone with submodules:

```bash
git clone --recurse-submodules https://github.com/ottosmops/ocfl
# or, if already cloned:
git submodule update --init --recursive
```

## Quick start

### Creating an object

```php
use Ottosmops\Ocfl\OcflObject;

$object = OcflObject::create(
    path: '/path/to/storage/my-object',
    id:   'urn:example:my-object',
);

$object = $object->newVersion()
    ->addContents('README.md', "# Hello, OCFL\n")
    ->addFile('data/report.pdf', '/tmp/report.pdf')
    ->withMessage('Initial import')
    ->withUser('Alice', 'mailto:alice@example.com')
    ->commit();

$object->head();                              // "v1"
$object->logicalPaths('v1');                  // ['README.md', 'data/report.pdf']
$object->readContent('v1', 'README.md');      // "# Hello, OCFL\n"
```

### Committing a new version

```php
$object = OcflObject::open('/path/to/storage/my-object')
    ->newVersion()
    ->addContents('CHANGELOG.md', "## v2\n- Added changelog\n")
    ->renameFile('README.md', 'README.txt')
    ->withMessage('Docs update')
    ->withUser('Alice', 'mailto:alice@example.com')
    ->commit();

$object->head();                              // "v2"
$object->resolveContentPath('v2', 'README.txt');
// → "v1/content/README.md" (dedup: not re-stored in v2)
```

### Using a storage root with an id-to-path layout

```php
use Ottosmops\Ocfl\Storage\StorageRoot;
use Ottosmops\Ocfl\Storage\HashedNTupleStorageLayout;

$root = StorageRoot::create(
    path:   '/path/to/storage',
    layout: new HashedNTupleStorageLayout(),
);

$root->createObject('urn:example:foo')
    ->newVersion()
    ->addContents('hello.txt', 'hi')
    ->commit();

// Later, in another process:
$root   = StorageRoot::open('/path/to/storage');
$object = $root->getObject('urn:example:foo');
$ids    = $root->listObjects();
```

### Checking out a version

```php
$object->checkout('/tmp/snapshot-v1', 'v1');
// Materialises the logical state of v1 into /tmp/snapshot-v1, verifying
// every content digest during the copy.
```

### Validating an object

```php
use Ottosmops\Ocfl\Validation\ErrorCode;

$report = OcflObject::open('/path/to/object')->validate();

$report->isValid();                           // bool — no errors
$report->hasWarnings();                       // bool
$report->hasError(ErrorCode::E040);           // bool
foreach ($report->errors() as $issue) {
    echo "[{$issue->code->value}] {$issue->message}\n";
}
```

## Cloud storage via Flysystem

Any `league/flysystem` v3 filesystem can host an OCFL storage root.

```php
use Aws\S3\S3Client;
use League\Flysystem\Filesystem as LeagueFilesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Ottosmops\Ocfl\Filesystem\FlysystemFilesystem;
use Ottosmops\Ocfl\Storage\StorageRoot;
use Ottosmops\Ocfl\Storage\HashedNTupleStorageLayout;

$client  = new S3Client([...]);
$league  = new LeagueFilesystem(new AwsS3V3Adapter($client, 'my-bucket'));
$fs      = new FlysystemFilesystem($league);

$root = StorageRoot::create('/archive', new HashedNTupleStorageLayout(), $fs);

$root->createObject('urn:example:foo')
    ->newVersion()
    ->addContents('doc.txt', 'content')
    ->withUser('Alice', 'mailto:alice@example.com')
    ->commit();
```

Content digests are streamed, not buffered — large files never need to be
loaded into memory just to hash them.

## Validation status

The `ObjectValidator` emits OCFL-spec error and warning codes that link
directly to <https://ocfl.io/1.1/spec/validation-codes.html>.

| Category | Coverage |
|----------|----------|
| Good-object fixtures | 12 / 12 validate with zero errors |
| Bad-object fixtures | 55 / 55 rejected with the documented error code |
| Warn-object fixtures | 13 / 13 emit the documented advisory |

Implemented error codes: `E001 E003 E007 E008 E010 E011 E013 E015 E017
E019 E023 E025 E033 E034 E036 E037 E038 E040 E041 E046 E048 E049 E050
E052 E053 E058 E060 E061 E063 E064 E066 E067 E070 E092 E093 E095 E096
E097 E099 E100 E101 E103 E107`.

Implemented warnings: `W001 W002 W004 W005 W007 W008 W009 W010 W011 W013`.

## Development

```bash
composer install
composer check         # pint + phpstan + pest
composer test          # pest
composer analyse       # phpstan level max
composer format        # laravel pint
composer refactor      # rector
```

CI runs on Ubuntu and macOS against PHP 8.3 and 8.4.

## References

- [OCFL 1.1 Specification](https://ocfl.io/1.1/spec/)
- [OCFL Implementation Notes](https://ocfl.io/1.1/implementation-notes/)
- [OCFL Validation Codes](https://ocfl.io/1.1/spec/validation-codes.html)
- [OCFL Community Extensions](https://ocfl.github.io/extensions/)
- [OCFL Fixtures](https://github.com/OCFL/fixtures) (used as test corpus)
- Related implementations: [ocfl-java](https://github.com/OCFL/ocfl-java),
  [ocflcore](https://github.com/inveniosoftware/ocflcore) (Python),
  [rocfl](https://github.com/pwinckles/rocfl) (Rust),
  [gocfl](https://github.com/ocfl-archive/gocfl) (Go).

## License

See [LICENSE](LICENSE).
