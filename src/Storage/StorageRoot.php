<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Storage;

use FilesystemIterator;
use JsonException;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Internal\Fs;
use Ottosmops\Ocfl\Namaste;
use Ottosmops\Ocfl\NamasteType;
use Ottosmops\Ocfl\OcflObject;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;
use SplFileInfo;

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
    ) {
    }

    public static function create(string $path, StorageLayout $layout): self
    {
        Fs::ensureDirectory($path);

        if ((new FilesystemIterator($path))->valid()) {
            throw new OcflException(ErrorCode::E001, "storage root {$path} is not empty");
        }

        Namaste::write($path, NamasteType::StorageRoot);

        $config = $layout->configuration();
        try {
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OcflException(ErrorCode::E001, 'failed to encode layout config', $e);
        }

        Fs::writeFile($path . DIRECTORY_SEPARATOR . self::LAYOUT_FILENAME, $json . "\n");

        return new self($path, $layout);
    }

    public static function open(string $path): self
    {
        if (! is_dir($path)) {
            throw new OcflException(ErrorCode::E003, "storage root does not exist: {$path}");
        }

        if (Namaste::find($path) !== NamasteType::StorageRoot) {
            throw new OcflException(
                ErrorCode::E003,
                "storage root NAMASTE declaration missing in {$path}",
            );
        }

        $layoutPath = $path . DIRECTORY_SEPARATOR . self::LAYOUT_FILENAME;
        if (! is_file($layoutPath)) {
            throw new OcflException(
                ErrorCode::E070,
                "ocfl_layout.json missing in storage root {$path}",
            );
        }

        $raw = file_get_contents($layoutPath);
        if ($raw === false) {
            throw new OcflException(ErrorCode::E070, "unable to read {$layoutPath}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OcflException(ErrorCode::E070, 'ocfl_layout.json is not valid JSON', $e);
        }

        if (! is_array($decoded) || ! isset($decoded['extensionName']) || ! is_string($decoded['extensionName'])) {
            throw new OcflException(ErrorCode::E070, 'ocfl_layout.json missing extensionName');
        }

        /** @var array<array-key, mixed> $decoded */
        return new self($path, self::layoutFromConfig($decoded));
    }

    public function layout(): StorageLayout
    {
        return $this->layout;
    }

    public function createObject(string $id, DigestAlgorithm $digestAlgorithm = DigestAlgorithm::Sha512): OcflObject
    {
        $objectPath = $this->pathFor($id);

        if (is_dir($objectPath)) {
            throw new OcflException(
                ErrorCode::E001,
                "object '{$id}' already exists at {$objectPath}",
            );
        }

        return OcflObject::create($objectPath, $id, $digestAlgorithm);
    }

    public function getObject(string $id): OcflObject
    {
        return OcflObject::open($this->pathFor($id));
    }

    public function hasObject(string $id): bool
    {
        $path = $this->pathFor($id);

        return is_dir($path) && Namaste::find($path) === NamasteType::ObjectRoot;
    }

    /**
     * Walk the storage root and return every OCFL object id found.
     *
     * Implementation note: the spec lets object roots appear at any depth,
     * so we walk the tree and stop descending at each object-root boundary
     * (an object root must not contain another object root).
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
        return $this->path . DIRECTORY_SEPARATOR . $this->layout->resolveObjectPath($id);
    }

    /**
     * @param  list<string>  $ids
     * @param-out list<string> $ids
     */
    private function collectObjectsBelow(string $directory, array &$ids): void
    {
        if (Namaste::find($directory) === NamasteType::ObjectRoot) {
            $ids[] = OcflObject::open($directory)->id();

            return;
        }

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isDir()) {
                $this->collectObjectsBelow($entry->getPathname(), $ids);
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
