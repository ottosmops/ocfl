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
 *   - Name the configured id-to-path layout in ocfl_layout.json (§4.1:
 *     exactly the keys `extension` and `description`) and store the
 *     layout's parameters in extensions/<extension>/config.json.
 *   - Place and retrieve OcflObjects by id through that layout.
 *
 * Roots written by v1.0.0 carry the layout configuration (with
 * `extensionName`) directly in ocfl_layout.json. They still open; see
 * hasLegacyLayoutDeclaration() and upgradeLayoutDeclaration().
 */
final class StorageRoot
{
    private const LAYOUT_FILENAME = 'ocfl_layout.json';

    private const EXTENSIONS_DIRECTORY = 'extensions';

    private const EXTENSION_CONFIG_FILENAME = 'config.json';

    private const LAYOUT_DESCRIPTIONS = [
        FlatDirectStorageLayout::EXTENSION_NAME => 'Flat direct storage layout: each object is stored in a'
            . ' directory named after its identifier, directly below the storage root. See'
            . ' https://ocfl.github.io/extensions/0002-flat-direct-storage-layout.html',
        HashedNTupleStorageLayout::EXTENSION_NAME => 'Hashed N-tuple storage layout: the object identifier is'
            . ' hashed and the digest split into N tuples to form the object path. Parameters in'
            . ' extensions/0004-hashed-n-tuple-storage-layout/config.json. See'
            . ' https://ocfl.github.io/extensions/0004-hashed-n-tuple-storage-layout.html',
    ];

    private function __construct(
        public readonly string $path,
        private readonly StorageLayout $layout,
        private readonly Filesystem $fs,
        private bool $legacyLayoutDeclaration = false,
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
        self::writeLayoutDeclaration($fs, $path, $layout);

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

        if (! is_array($decoded)) {
            throw new OcflException(ErrorCode::E070, 'ocfl_layout.json is not a JSON object');
        }

        // Spec form (§4.1): {"extension": ..., "description": ...}, with the
        // parameters in extensions/<extension>/config.json.
        if (array_key_exists('extension', $decoded)) {
            if (! is_string($decoded['extension'])) {
                throw new OcflException(ErrorCode::E070, 'ocfl_layout.json extension must be a string');
            }

            $name = self::assertSupportedLayout($decoded['extension']);

            return new self($path, self::layoutFromConfig(self::readExtensionConfig($fs, $path, $name)), $fs);
        }

        // v1.0.0 form: the extension configuration written into ocfl_layout.json.
        if (isset($decoded['extensionName']) && is_string($decoded['extensionName'])) {
            self::assertSupportedLayout($decoded['extensionName']);

            return new self($path, self::layoutFromConfig($decoded), $fs, legacyLayoutDeclaration: true);
        }

        throw new OcflException(ErrorCode::E070, 'ocfl_layout.json missing extension');
    }

    public function layout(): StorageLayout
    {
        return $this->layout;
    }

    /**
     * True when this root was written by ottosmops/ocfl v1.0.0, which put the
     * layout configuration into ocfl_layout.json instead of the two keys
     * required by OCFL 1.1 §4.1. Such a root works, but other OCFL
     * implementations may not recognise its layout.
     */
    public function hasLegacyLayoutDeclaration(): bool
    {
        return $this->legacyLayoutDeclaration;
    }

    /**
     * Rewrite a v1.0.0-style layout declaration into the spec form:
     * extensions/<extension>/config.json first, then ocfl_layout.json.
     * Objects are not touched. No-op on a conformant root.
     */
    public function upgradeLayoutDeclaration(): void
    {
        if (! $this->legacyLayoutDeclaration) {
            return;
        }

        self::writeLayoutDeclaration($this->fs, $this->path, $this->layout);
        $this->legacyLayoutDeclaration = false;
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
     * Write extensions/<extension>/config.json, then ocfl_layout.json, so a
     * crash in between never leaves a declaration pointing at a missing config.
     */
    private static function writeLayoutDeclaration(Filesystem $fs, string $path, StorageLayout $layout): void
    {
        $name = $layout->extensionName();
        $declaration = [
            'extension' => $name,
            'description' => self::LAYOUT_DESCRIPTIONS[$name] ?? "Storage layout per OCFL extension {$name}",
        ];

        $fs->write(self::extensionConfigPath($path, $name), self::encodeJson($layout->configuration()));
        $fs->write($path . '/' . self::LAYOUT_FILENAME, self::encodeJson($declaration));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function encodeJson(array $data): string
    {
        try {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $e) {
            throw new OcflException(ErrorCode::E001, 'failed to encode layout declaration', $e);
        }
    }

    private static function extensionConfigPath(string $path, string $name): string
    {
        return $path . '/' . self::EXTENSIONS_DIRECTORY . '/' . $name . '/' . self::EXTENSION_CONFIG_FILENAME;
    }

    /**
     * Only known layout names pass, which also keeps the name safe to use
     * as a path segment.
     */
    private static function assertSupportedLayout(string $name): string
    {
        if (! array_key_exists($name, self::LAYOUT_DESCRIPTIONS)) {
            throw new OcflException(ErrorCode::E070, "unsupported storage layout extension '{$name}'");
        }

        return $name;
    }

    /**
     * Read extensions/<name>/config.json. A missing file means the
     * extension's defaults apply.
     *
     * @return array<array-key, mixed>
     */
    private static function readExtensionConfig(Filesystem $fs, string $path, string $name): array
    {
        $configPath = self::extensionConfigPath($path, $name);
        if (! $fs->fileExists($configPath)) {
            return ['extensionName' => $name];
        }

        try {
            /** @var mixed $config */
            $config = json_decode($fs->read($configPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OcflException(ErrorCode::E070, "{$configPath} is not valid JSON", $e);
        }

        if (! is_array($config)) {
            throw new OcflException(ErrorCode::E070, "{$configPath} is not a JSON object");
        }

        $configured = $config['extensionName'] ?? $name;
        if ($configured !== $name) {
            throw new OcflException(
                ErrorCode::E070,
                "{$configPath} names extension '" . (is_string($configured) ? $configured : '?')
                . "', but ocfl_layout.json declares '{$name}'",
            );
        }

        return ['extensionName' => $name] + $config;
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
