<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Storage;

use JsonException;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\Filesystem;
use Ottosmops\Ocfl\Filesystem\LocalFilesystem;
use Ottosmops\Ocfl\Namaste;
use Ottosmops\Ocfl\NamasteType;
use Ottosmops\Ocfl\OcflObject;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;

/**
 * An OCFL 1.1 Storage Root (spec §3).
 *
 * Responsibilities:
 *   - Declare itself with a NAMASTE file (0=ocfl_1.1).
 *   - Record the configured id-to-path layout in ocfl_layout.json.
 *   - Place and retrieve OcflObjects by id through that layout.
 */
final class StorageRoot
{
    private const LAYOUT_FILENAME = 'ocfl_layout.json';

    private function __construct(
        public readonly string $path,
        private readonly StorageLayout $layout,
        private readonly Filesystem $fs,
    ) {
    }

    public static function create(string $path, StorageLayout $layout, ?Filesystem $fs = null): self
    {
        $fs ??= new LocalFilesystem();
        $fs->createDirectory($path);

        if ($fs->listDirectory($path) !== []) {
            throw new OcflException(ErrorCode::E001, "storage root {$path} is not empty");
        }

        Namaste::write($fs, $path, NamasteType::StorageRoot);

        $config = $layout->configuration();
        try {
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OcflException(ErrorCode::E001, 'failed to encode layout config', $e);
        }

        $fs->write($path . '/' . self::LAYOUT_FILENAME, $json . "\n");

        return new self($path, $layout, $fs);
    }

    public static function open(string $path, ?Filesystem $fs = null): self
    {
        $fs ??= new LocalFilesystem();

        if (! $fs->directoryExists($path)) {
            throw new OcflException(ErrorCode::E003, "storage root does not exist: {$path}");
        }

        if (Namaste::find($fs, $path) !== NamasteType::StorageRoot) {
            throw new OcflException(
                ErrorCode::E003,
                "storage root NAMASTE declaration missing in {$path}",
            );
        }

        $layoutPath = $path . '/' . self::LAYOUT_FILENAME;
        if (! $fs->fileExists($layoutPath)) {
            throw new OcflException(
                ErrorCode::E070,
                "ocfl_layout.json missing in storage root {$path}",
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($fs->read($layoutPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OcflException(ErrorCode::E070, 'ocfl_layout.json is not valid JSON', $e);
        }

        if (! is_array($decoded) || ! isset($decoded['extensionName']) || ! is_string($decoded['extensionName'])) {
            throw new OcflException(ErrorCode::E070, 'ocfl_layout.json missing extensionName');
        }

        /** @var array<array-key, mixed> $decoded */
        return new self($path, self::layoutFromConfig($decoded), $fs);
    }

    public function layout(): StorageLayout
    {
        return $this->layout;
    }

    public function createObject(string $id, DigestAlgorithm $digestAlgorithm = DigestAlgorithm::Sha512): OcflObject
    {
        $objectPath = $this->pathFor($id);

        if ($this->fs->directoryExists($objectPath)) {
            throw new OcflException(
                ErrorCode::E001,
                "object '{$id}' already exists at {$objectPath}",
            );
        }

        return OcflObject::create($objectPath, $id, $digestAlgorithm, $this->fs);
    }

    public function getObject(string $id): OcflObject
    {
        return OcflObject::open($this->pathFor($id), $this->fs);
    }

    public function hasObject(string $id): bool
    {
        $path = $this->pathFor($id);

        return $this->fs->directoryExists($path)
            && Namaste::find($this->fs, $path) === NamasteType::ObjectRoot;
    }

    /**
     * Walk the storage root and return every OCFL object id found.
     *
     * @return list<string>
     */
    public function listObjects(): array
    {
        $ids = [];
        $this->collectObjectsBelow($this->path, $ids);

        return $ids;
    }

    private function pathFor(string $id): string
    {
        return $this->path . '/' . $this->layout->resolveObjectPath($id);
    }

    /**
     * @param  list<string>  $ids
     * @param-out list<string> $ids
     */
    private function collectObjectsBelow(string $directory, array &$ids): void
    {
        if (Namaste::find($this->fs, $directory) === NamasteType::ObjectRoot) {
            $ids[] = OcflObject::open($directory, $this->fs)->id();

            return;
        }

        foreach ($this->fs->listDirectory($directory) as $name) {
            $entryPath = $directory . '/' . $name;
            if ($this->fs->directoryExists($entryPath)) {
                $this->collectObjectsBelow($entryPath, $ids);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function layoutFromConfig(array $config): StorageLayout
    {
        $name = is_string($config['extensionName'] ?? null) ? $config['extensionName'] : '';

        return match ($name) {
            FlatDirectStorageLayout::EXTENSION_NAME => new FlatDirectStorageLayout(),
            HashedNTupleStorageLayout::EXTENSION_NAME => self::hashedNTupleFromConfig($config),
            default => throw new OcflException(
                ErrorCode::E070,
                "unsupported storage layout extension '{$name}'",
            ),
        };
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function hashedNTupleFromConfig(array $config): HashedNTupleStorageLayout
    {
        $algorithm = isset($config['digestAlgorithm']) && is_string($config['digestAlgorithm'])
            ? DigestAlgorithm::tryFrom($config['digestAlgorithm'])
            : null;

        return new HashedNTupleStorageLayout(
            digestAlgorithm: $algorithm ?? DigestAlgorithm::Sha256,
            tupleSize: is_int($config['tupleSize'] ?? null) ? $config['tupleSize'] : 3,
            numberOfTuples: is_int($config['numberOfTuples'] ?? null) ? $config['numberOfTuples'] : 3,
            shortObjectRoot: (bool) ($config['shortObjectRoot'] ?? false),
        );
    }
}
