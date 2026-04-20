<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

use Ottosmops\Ocfl\Filesystem\Filesystem;
use Ottosmops\Ocfl\Filesystem\LocalFilesystem;
use Ottosmops\Ocfl\Inventory\Inventory;
use Ottosmops\Ocfl\Inventory\InventoryReader;
use Ottosmops\Ocfl\Inventory\InventorySidecar;
use Ottosmops\Ocfl\Inventory\Version;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\ObjectValidator;
use Ottosmops\Ocfl\Validation\OcflException;
use Ottosmops\Ocfl\Validation\ValidationReport;
use OutOfBoundsException;

/**
 * Read + write facade for an on-disk (or cloud-disk) OCFL 1.1 object.
 *
 * All filesystem access is mediated by the injected Filesystem; callers
 * that omit one get a LocalFilesystem for POSIX-style host I/O.
 */
final readonly class OcflObject
{
    public function __construct(
        public string $path,
        public Inventory $inventory,
        public Filesystem $fs,
    ) {
    }

    public static function open(string $path, ?Filesystem $fs = null): self
    {
        $fs ??= new LocalFilesystem();

        if (! $fs->directoryExists($path)) {
            throw new OcflException(ErrorCode::E003, "object root does not exist: {$path}");
        }

        $namaste = Namaste::find($fs, $path);

        if ($namaste !== NamasteType::ObjectRoot) {
            throw new OcflException(
                ErrorCode::E003,
                "object root NAMASTE declaration missing in {$path}",
            );
        }

        // A freshly-created object has NAMASTE but no inventory yet
        // (nothing has been committed). Return an empty-inventory shell so
        // open() is symmetrical with create().
        $inventoryPath = $path . '/' . Inventory::FILENAME;
        if (! $fs->fileExists($inventoryPath)) {
            return new self($path, self::emptyInventory($fs, $path), $fs);
        }

        $inventory = InventoryReader::fromFilesystem($fs, $inventoryPath);

        if (! InventorySidecar::verify($fs, $path, $inventory->digestAlgorithm)) {
            throw new OcflException(
                ErrorCode::E060,
                "inventory sidecar digest does not match inventory.json in {$path}",
            );
        }

        return new self($path, $inventory, $fs);
    }

    private static function emptyInventory(Filesystem $fs, string $path): Inventory
    {
        $id = '';
        $algorithm = DigestAlgorithm::Sha512;

        $pendingPath = $path . '/' . self::PENDING_META;
        if ($fs->fileExists($pendingPath)) {
            /** @var array{id?: string, digestAlgorithm?: string} $meta */
            $meta = json_decode($fs->read($pendingPath), true, flags: JSON_THROW_ON_ERROR);
            $id = (string) ($meta['id'] ?? '');
            if (isset($meta['digestAlgorithm'])) {
                $algorithm = DigestAlgorithm::tryFrom($meta['digestAlgorithm']) ?? DigestAlgorithm::Sha512;
            }
        }

        return new Inventory(
            id: $id,
            type: Inventory::TYPE,
            digestAlgorithm: $algorithm,
            head: '',
            contentDirectory: Inventory::DEFAULT_CONTENT_DIRECTORY,
            manifest: [],
            versions: [],
        );
    }

    /**
     * Initialise a new, empty OCFL object at $path.
     *
     * The NAMASTE declaration is written immediately; no inventory is
     * persisted until the first commit.
     */
    public static function create(
        string $path,
        string $id,
        DigestAlgorithm $digestAlgorithm = DigestAlgorithm::Sha512,
        ?Filesystem $fs = null,
    ): self {
        $fs ??= new LocalFilesystem();
        $fs->createDirectory($path);

        // An object root MUST be empty before initialisation (§3.3).
        if ($fs->listDirectory($path) !== []) {
            throw new OcflException(ErrorCode::E001, "object root {$path} is not empty");
        }

        Namaste::write($fs, $path, NamasteType::ObjectRoot);

        // Persist enough state so a later CLI invocation (create then
        // commit in separate processes) can recover the id and chosen
        // digest algorithm. The file is removed by the first commit.
        $fs->write(
            $path . '/' . self::PENDING_META,
            (string) json_encode([
                'id' => $id,
                'digestAlgorithm' => $digestAlgorithm->value,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        $emptyInventory = new Inventory(
            id: $id,
            type: Inventory::TYPE,
            digestAlgorithm: $digestAlgorithm,
            head: '',
            contentDirectory: Inventory::DEFAULT_CONTENT_DIRECTORY,
            manifest: [],
            versions: [],
        );

        return new self($path, $emptyInventory, $fs);
    }

    public const PENDING_META = '.ocfl-pending-meta.json';

    public function newVersion(): VersionBuilder
    {
        return new VersionBuilder($this);
    }

    public function validate(): ValidationReport
    {
        return (new ObjectValidator())->validate($this->path, $this->fs);
    }

    public function id(): string
    {
        return $this->inventory->id;
    }

    public function head(): string
    {
        return $this->inventory->head;
    }

    /**
     * @return list<string>
     */
    public function versionNames(): array
    {
        return $this->inventory->versionSequence();
    }

    /**
     * @return list<string>
     */
    public function logicalPaths(string $version): array
    {
        $state = $this->requireVersion($version)->state;
        $paths = [];

        foreach ($state as $logicalPaths) {
            foreach ($logicalPaths as $path) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * OCFL uses forward-delta dedup: an unchanged file's content entry may
     * still live in the v1 content directory even when referenced from v3.
     */
    public function resolveContentPath(string $version, string $logicalPath): string
    {
        $state = $this->requireVersion($version)->state;

        foreach ($state as $digest => $paths) {
            if (in_array($logicalPath, $paths, true)) {
                return $this->contentPathForDigest($digest, $version, $logicalPath);
            }
        }

        throw new OutOfBoundsException(
            "logical path '{$logicalPath}' not present in version '{$version}'",
        );
    }

    public function readContent(string $version, string $logicalPath): string
    {
        $relative = $this->resolveContentPath($version, $logicalPath);
        $absolute = $this->path . '/' . $relative;

        if (! $this->fs->fileExists($absolute)) {
            throw new OcflException(ErrorCode::E092, "content file missing at {$absolute}");
        }

        return $this->fs->read($absolute);
    }

    /**
     * Materialise a version's logical state into a target directory.
     */
    public function checkout(string $targetDirectory, ?string $version = null): void
    {
        $version ??= $this->inventory->head;
        $state = $this->requireVersion($version)->state;
        $this->fs->createDirectory($targetDirectory);

        foreach ($state as $digest => $logicalPaths) {
            $source = $this->path . '/' . $this->contentPathForDigest($digest, $version, $logicalPaths[0]);

            foreach ($logicalPaths as $logicalPath) {
                $destination = $targetDirectory . '/' . $logicalPath;
                $this->fs->createDirectory(dirname($destination));
                $this->fs->copy($source, $destination);

                $actual = $this->fs->digestFile($destination, $this->inventory->digestAlgorithm);

                if (! Digest::equals($actual, $digest)) {
                    throw new OcflException(
                        ErrorCode::E092,
                        "digest mismatch after checkout of '{$logicalPath}'",
                    );
                }
            }
        }
    }

    private function contentPathForDigest(string $digest, string $version, string $logicalPath): string
    {
        $candidates = $this->inventory->manifest[$digest] ?? null;

        if ($candidates === null || $candidates === []) {
            throw new OcflException(
                ErrorCode::E092,
                "digest for logical path '{$logicalPath}' in version '{$version}' not present in manifest",
            );
        }

        return $candidates[0];
    }

    private function requireVersion(string $version): Version
    {
        if (! isset($this->inventory->versions[$version])) {
            throw new OutOfBoundsException("version '{$version}' not present in object");
        }

        return $this->inventory->versions[$version];
    }
}
