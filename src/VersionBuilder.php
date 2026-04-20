<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

use DateTimeImmutable;
use Ottosmops\Ocfl\Internal\Fs;
use Ottosmops\Ocfl\Inventory\Inventory;
use Ottosmops\Ocfl\Inventory\InventorySidecar;
use Ottosmops\Ocfl\Inventory\InventoryWriter;
use Ottosmops\Ocfl\Inventory\User;
use Ottosmops\Ocfl\Inventory\Version;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;
use RuntimeException;

/**
 * Stages changes against an OcflObject's head version and commits them as
 * the next OCFL version.
 *
 * Semantics:
 *   - Starts from a copy of the base version's logical state.
 *   - add* / remove / rename mutate only the staged logical state.
 *   - commit() materialises a new version directory, writing only the
 *     content files whose digests are not yet present in the object's
 *     manifest (forward-delta dedup).
 *   - Root and version-dir inventories are written together, then the root
 *     inventory is renamed into place as the atomic commit point.
 */
final class VersionBuilder
{
    /** @var array<string, StagedContent> logicalPath → staged content */
    private array $pendingAdds = [];

    /** @var list<string> */
    private array $pendingRemoves = [];

    /** @var array<string, string> from → to */
    private array $pendingRenames = [];

    private ?string $message = null;

    private ?User $user = null;

    private ?DateTimeImmutable $created = null;

    public function __construct(private readonly OcflObject $base)
    {
    }

    public function addFile(string $logicalPath, string $sourcePath): self
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException("source file not readable: {$sourcePath}");
        }

        $this->pendingAdds[$logicalPath] = new StagedContent(sourcePath: $sourcePath);

        return $this;
    }

    public function addContents(string $logicalPath, string $bytes): self
    {
        $this->pendingAdds[$logicalPath] = new StagedContent(inlineBytes: $bytes);

        return $this;
    }

    public function removeFile(string $logicalPath): self
    {
        $this->pendingRemoves[] = $logicalPath;

        return $this;
    }

    public function renameFile(string $from, string $to): self
    {
        $this->pendingRenames[$from] = $to;

        return $this;
    }

    public function withMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function withUser(string $name, ?string $address = null): self
    {
        $this->user = new User(name: $name, address: $address);

        return $this;
    }

    public function withCreated(DateTimeImmutable $timestamp): self
    {
        $this->created = $timestamp;

        return $this;
    }

    public function commit(): OcflObject
    {
        $baseInventory = $this->base->inventory;
        $nextVersionName = self::nextVersionName($baseInventory->head);
        $algorithm = $baseInventory->digestAlgorithm;

        $baseState = $baseInventory->versions === []
            ? []
            : self::inheritState($baseInventory);

        [$newState, $newManifestAdditions, $filesToWrite] = $this->applyStagedChanges(
            baseState: $baseState,
            baseManifest: $baseInventory->manifest,
            nextVersionName: $nextVersionName,
            contentDirectory: $baseInventory->contentDirectory,
            algorithm: $algorithm,
        );

        if ($this->isNoop($baseState, $newState)) {
            throw new RuntimeException('commit() called with no staged changes');
        }

        $newManifest = $baseInventory->manifest;
        foreach ($newManifestAdditions as $digest => $paths) {
            $existing = $newManifest[$digest] ?? [];
            $newManifest[$digest] = array_values(array_unique([...$existing, ...$paths]));
        }
        ksort($newManifest);

        $versionEntry = new Version(
            created: $this->created ?? new DateTimeImmutable('now'),
            state: $newState,
            message: $this->message,
            user: $this->user,
        );

        $newVersions = $baseInventory->versions;
        $newVersions[$nextVersionName] = $versionEntry;

        $newInventory = new Inventory(
            id: $baseInventory->id,
            type: $baseInventory->type,
            digestAlgorithm: $algorithm,
            head: $nextVersionName,
            contentDirectory: $baseInventory->contentDirectory,
            manifest: $newManifest,
            versions: $newVersions,
            fixity: $baseInventory->fixity,
        );

        $this->materialise(
            objectRoot: $this->base->path,
            versionName: $nextVersionName,
            inventory: $newInventory,
            filesToWrite: $filesToWrite,
            algorithm: $algorithm,
        );

        return OcflObject::open($this->base->path);
    }

    /**
     * @param  array<string, list<string>>  $baseState
     * @param  array<string, list<string>>  $baseManifest
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>, 2: array<string, StagedContent>}
     */
    private function applyStagedChanges(
        array $baseState,
        array $baseManifest,
        string $nextVersionName,
        string $contentDirectory,
        DigestAlgorithm $algorithm,
    ): array {
        $digestByLogical = self::digestByLogicalPath($baseState);

        foreach ($this->pendingRemoves as $logicalPath) {
            unset($digestByLogical[$logicalPath]);
        }

        foreach ($this->pendingRenames as $from => $to) {
            if (! array_key_exists($from, $digestByLogical)) {
                throw new RuntimeException("cannot rename '{$from}': path not in base version");
            }
            $digestByLogical[$to] = $digestByLogical[$from];
            unset($digestByLogical[$from]);
        }

        $newManifestAdditions = [];
        $filesToWrite = [];

        foreach ($this->pendingAdds as $logicalPath => $staged) {
            $digest = $staged->digest($algorithm);
            $digestByLogical[$logicalPath] = $digest;

            $dedupHit = isset($baseManifest[$digest]) || isset($newManifestAdditions[$digest]);
            if ($dedupHit) {
                continue;
            }

            $contentPath = $nextVersionName . '/' . $contentDirectory . '/' . $logicalPath;
            $newManifestAdditions[$digest] = [$contentPath];
            $filesToWrite[$contentPath] = $staged;
        }

        $newState = [];
        foreach ($digestByLogical as $logicalPath => $digest) {
            $newState[$digest][] = $logicalPath;
        }
        foreach ($newState as &$paths) {
            sort($paths);
        }
        ksort($newState);

        return [$newState, $newManifestAdditions, $filesToWrite];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function inheritState(Inventory $inventory): array
    {
        return $inventory->versions[$inventory->head]->state;
    }

    /**
     * @param  array<string, list<string>>  $state
     * @return array<string, string>
     */
    private static function digestByLogicalPath(array $state): array
    {
        $out = [];
        foreach ($state as $digest => $paths) {
            foreach ($paths as $path) {
                $out[$path] = $digest;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $baseState
     * @param  array<string, list<string>>  $newState
     */
    private function isNoop(array $baseState, array $newState): bool
    {
        return self::normalizeState($baseState) === self::normalizeState($newState);
    }

    /**
     * @param  array<string, list<string>>  $state
     * @return array<string, list<string>>
     */
    private static function normalizeState(array $state): array
    {
        foreach ($state as $digest => $paths) {
            sort($paths);
            $state[$digest] = $paths;
        }
        ksort($state);

        return $state;
    }

    /**
     * @param  array<string, StagedContent>  $filesToWrite
     */
    private function materialise(
        string $objectRoot,
        string $versionName,
        Inventory $inventory,
        array $filesToWrite,
        DigestAlgorithm $algorithm,
    ): void {
        $versionDir = $objectRoot . DIRECTORY_SEPARATOR . $versionName;

        if (is_dir($versionDir)) {
            throw new OcflException(ErrorCode::E001, "version directory already exists: {$versionDir}");
        }

        // Stage the version in a sibling temp directory; atomically rename on
        // success so a crash during content-copy leaves only a discardable
        // .tmp-XXXX directory behind, not a half-formed vN/.
        $stagingDir = $objectRoot . DIRECTORY_SEPARATOR . $versionName . '.tmp-' . bin2hex(random_bytes(4));
        Fs::ensureDirectory($stagingDir);

        try {
            foreach ($filesToWrite as $relativePath => $staged) {
                // $relativePath begins with "$versionName/"; redirect to the
                // staging dir while preserving the inner structure.
                $staged->writeTo(self::redirectToStaging(
                    $relativePath,
                    $versionName,
                    $stagingDir,
                    create: true,
                ));
            }

            $json = InventoryWriter::toJson($inventory);
            Fs::writeFile($stagingDir . DIRECTORY_SEPARATOR . Inventory::FILENAME, $json);
            InventorySidecar::writeFor($stagingDir, $algorithm);

            if (! rename($stagingDir, $versionDir)) {
                throw new OcflException(ErrorCode::E001, "failed to promote staging dir to {$versionDir}");
            }
        } catch (\Throwable $e) {
            self::removeDirectory($stagingDir);

            throw $e;
        }

        // Root inventory goes last so a crash mid-commit leaves the old head
        // in place, pointing at the previous version directory.
        $rootInventoryTmp = $objectRoot . DIRECTORY_SEPARATOR . Inventory::FILENAME . '.tmp-' . bin2hex(random_bytes(4));
        Fs::writeFile($rootInventoryTmp, InventoryWriter::toJson($inventory));

        if (! rename($rootInventoryTmp, $objectRoot . DIRECTORY_SEPARATOR . Inventory::FILENAME)) {
            @unlink($rootInventoryTmp);
            throw new OcflException(ErrorCode::E001, 'failed to update root inventory');
        }

        InventorySidecar::writeFor($objectRoot, $algorithm);
    }

    private static function redirectToStaging(
        string $relativePath,
        string $versionName,
        string $stagingDir,
        bool $create,
    ): string {
        $innerPath = substr($relativePath, strlen($versionName) + 1);
        $destination = $stagingDir . DIRECTORY_SEPARATOR . $innerPath;

        if ($create) {
            Fs::ensureDirectory(dirname($destination));
        }

        return $destination;
    }

    private static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir((string) $entry) : @unlink((string) $entry);
        }

        @rmdir($path);
    }

    private static function nextVersionName(string $head): string
    {
        if ($head === '') {
            return 'v1';
        }

        if (! preg_match('/^v(\d+)$/', $head, $matches)) {
            throw new OcflException(
                ErrorCode::E040,
                "unsupported version name format: '{$head}'",
            );
        }

        return 'v' . ((int) $matches[1] + 1);
    }
}
