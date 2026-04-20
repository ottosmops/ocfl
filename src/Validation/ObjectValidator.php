<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Validation;

use Ottosmops\Ocfl\Digest;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\Filesystem;
use Ottosmops\Ocfl\Filesystem\LocalFilesystem;
use Ottosmops\Ocfl\Inventory\Inventory;
use Ottosmops\Ocfl\Inventory\InventoryReader;
use Ottosmops\Ocfl\Inventory\InventorySidecar;
use Ottosmops\Ocfl\Namaste;
use Ottosmops\Ocfl\NamasteType;
use Throwable;

/**
 * Validates an OCFL 1.1 object root against the structural requirements of
 * the specification (https://ocfl.io/1.1/spec/validation-codes.html).
 *
 * The validator collects all detectable issues into a ValidationReport
 * rather than throwing on the first failure, so callers can emit a full
 * diagnostic in a single pass.
 */
final class ObjectValidator
{
    private const ALLOWED_ROOT_NAMES = ['inventory.json', '0=ocfl_object_1.1', 'extensions', 'logs'];

    public function validate(string $objectRoot, ?Filesystem $fs = null): ValidationReport
    {
        $fs ??= new LocalFilesystem();
        $report = new ValidationReport();

        if (! $fs->directoryExists($objectRoot)) {
            $report->addError(ErrorCode::E003, "object root does not exist: {$objectRoot}");

            return $report;
        }

        if ($fs->listDirectory($objectRoot) === []) {
            $report->addError(ErrorCode::E003, 'object root is empty');

            return $report;
        }

        $this->checkNamaste($fs, $objectRoot, $report);
        $inventory = $this->loadAndValidateRootInventory($fs, $objectRoot, $report);

        if ($inventory === null) {
            return $report;
        }

        $this->checkRootLayout($fs, $objectRoot, $inventory, $report);
        $this->checkVersionDirectories($fs, $objectRoot, $inventory, $report);
        $this->checkVersionInventories($fs, $objectRoot, $inventory, $report);
        $this->checkContentFilesAgainstManifest($fs, $objectRoot, $inventory, $report);
        $this->checkLogicalPathFormat($inventory, $report);
        $this->checkLogicalPathUniqueness($inventory, $report);
        $this->checkManifestCoverage($inventory, $report);
        $this->emitWarnings($inventory, $report);

        return $report;
    }

    private function checkNamaste(Filesystem $fs, string $objectRoot, ValidationReport $report): void
    {
        try {
            $namaste = Namaste::find($fs, $objectRoot);
        } catch (Throwable $e) {
            $report->addError(ErrorCode::E007, $e->getMessage());

            return;
        }

        if ($namaste === null) {
            $report->addError(ErrorCode::E003, 'missing NAMASTE declaration 0=ocfl_object_1.1');

            return;
        }

        if ($namaste !== NamasteType::ObjectRoot) {
            $report->addError(ErrorCode::E007, 'NAMASTE is not an object-root declaration');
        }
    }

    private function loadAndValidateRootInventory(Filesystem $fs, string $objectRoot, ValidationReport $report): ?Inventory
    {
        $inventoryPath = $objectRoot . '/' . Inventory::FILENAME;

        if (! $fs->fileExists($inventoryPath)) {
            $report->addError(ErrorCode::E063, 'root inventory.json missing');

            return null;
        }

        try {
            $inventory = InventoryReader::fromFilesystem($fs, $inventoryPath);
        } catch (OcflException $e) {
            $report->addError($e->errorCode, $e->getMessage());

            return null;
        }

        $this->checkSidecar($fs, $objectRoot, $inventory, $report);

        return $inventory;
    }

    private function checkSidecar(Filesystem $fs, string $directory, Inventory $inventory, ValidationReport $report): void
    {
        $sidecarPath = $directory . '/' . InventorySidecar::filename($inventory->digestAlgorithm);

        if (! $fs->fileExists($sidecarPath)) {
            $report->addError(ErrorCode::E058, "sidecar missing: {$sidecarPath}");

            return;
        }

        try {
            if (! InventorySidecar::verify($fs, $directory, $inventory->digestAlgorithm)) {
                $report->addError(ErrorCode::E060, 'sidecar digest does not match inventory.json', $sidecarPath);
            }
        } catch (OcflException $e) {
            $report->addError($e->errorCode, $e->getMessage(), $sidecarPath);
        }
    }

    private function checkRootLayout(Filesystem $fs, string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        $versionPattern = '/^v\d+$/';
        $sidecarFilename = InventorySidecar::filename($inventory->digestAlgorithm);

        foreach ($fs->listDirectory($objectRoot) as $name) {
            if (in_array($name, self::ALLOWED_ROOT_NAMES, true) || $name === $sidecarFilename) {
                continue;
            }

            $entryPath = $objectRoot . '/' . $name;
            $isDir = $fs->directoryExists($entryPath);

            if ($isDir && preg_match($versionPattern, $name) === 1) {
                continue;
            }

            $report->addError(
                ErrorCode::E001,
                $isDir
                    ? "unexpected directory in object root: {$name}"
                    : "unexpected file in object root: {$name}",
                $name,
            );
        }

        $extensionsPath = $objectRoot . '/extensions';
        if ($fs->directoryExists($extensionsPath)) {
            foreach ($fs->listDirectory($extensionsPath) as $name) {
                if (! $fs->directoryExists($extensionsPath . '/' . $name)) {
                    $report->addError(ErrorCode::E067, "extensions/ contains non-directory: {$name}");
                }
            }
        }
    }

    private function checkVersionDirectories(Filesystem $fs, string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        $versionNames = array_keys($inventory->versions);

        foreach ($versionNames as $versionName) {
            if (preg_match('/^v\d+$/', $versionName) !== 1) {
                $report->addError(ErrorCode::E011, "malformed version name '{$versionName}'");
            }
        }

        $padding = self::detectPaddingWidth($versionNames);
        if ($padding !== null && $padding > 1) {
            $report->addWarning(
                ErrorCode::W001,
                "version directory names are zero-padded ({$versionNames[0]})",
            );
        }

        $expected = 1;
        foreach ($versionNames as $name) {
            if (preg_match('/^v0*(\d+)$/', $name, $matches) !== 1) {
                continue;
            }
            $number = (int) $matches[1];
            if ($number !== $expected) {
                $report->addError(
                    ErrorCode::E010,
                    "version sequence broken; expected v{$expected} but got {$name}",
                );
                break;
            }
            $expected++;
        }

        $last = $versionNames === [] ? null : $versionNames[array_key_last($versionNames)];
        if ($last !== null && $inventory->head !== $last) {
            $report->addError(
                ErrorCode::E040,
                "head '{$inventory->head}' is not the most recent version '{$last}'",
            );
        }

        foreach ($versionNames as $versionName) {
            $versionPath = $objectRoot . '/' . $versionName;
            if (! $fs->directoryExists($versionPath)) {
                $report->addError(ErrorCode::E010, "version directory missing on disk: {$versionName}");

                continue;
            }

            $versionInventoryPath = $versionPath . '/' . Inventory::FILENAME;
            if (! $fs->fileExists($versionInventoryPath)) {
                $report->addWarning(ErrorCode::W010, 'version directory has no inventory.json', $versionName);
            }
        }
    }

    private function checkVersionInventories(Filesystem $fs, string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        foreach (array_keys($inventory->versions) as $versionName) {
            $versionInventoryPath = $objectRoot . '/' . $versionName . '/' . Inventory::FILENAME;
            if (! $fs->fileExists($versionInventoryPath)) {
                continue;
            }

            try {
                $versionInventory = InventoryReader::fromFilesystem($fs, $versionInventoryPath);
            } catch (OcflException $e) {
                $report->addError($e->errorCode, $e->getMessage(), $versionName);

                continue;
            }

            if ($versionInventory->id !== $inventory->id) {
                $report->addError(
                    ErrorCode::E037,
                    "version inventory id '{$versionInventory->id}' differs from root id '{$inventory->id}'",
                    $versionName,
                );
            }

            if ($versionInventory->type !== $inventory->type) {
                $report->addError(
                    ErrorCode::E103,
                    "version inventory type '{$versionInventory->type}' differs from root type '{$inventory->type}'",
                    $versionName,
                );
            }
        }

        $headVersionInventoryPath = $objectRoot . '/' . $inventory->head . '/' . Inventory::FILENAME;

        if ($fs->fileExists($headVersionInventoryPath)) {
            $rootJson = $fs->read($objectRoot . '/' . Inventory::FILENAME);
            $headJson = $fs->read($headVersionInventoryPath);

            if ($rootJson !== $headJson) {
                $report->addError(
                    ErrorCode::E064,
                    'root inventory does not match head version inventory byte-for-byte',
                    $inventory->head,
                );
            }
        }
    }

    private function checkContentFilesAgainstManifest(Filesystem $fs, string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        $contentDir = $inventory->contentDirectory;
        $onDisk = [];

        foreach (array_keys($inventory->versions) as $versionName) {
            $versionContent = $objectRoot . '/' . $versionName . '/' . $contentDir;

            foreach ($fs->listFilesRecursively($versionContent) as $relative) {
                $onDisk[$versionName . '/' . $contentDir . '/' . $relative] =
                    $versionContent . '/' . $relative;
            }

            // Flag files under the version directory but outside contentDirectory.
            $versionPath = $objectRoot . '/' . $versionName;
            if (! $fs->directoryExists($versionPath)) {
                continue;
            }
            foreach ($fs->listDirectory($versionPath) as $name) {
                if ($name === $contentDir) {
                    continue;
                }
                if ($name === Inventory::FILENAME || $name === InventorySidecar::filename($inventory->digestAlgorithm)) {
                    continue;
                }
                if ($fs->directoryExists($versionPath . '/' . $name)) {
                    $report->addError(
                        ErrorCode::E015,
                        "file or directory outside contentDirectory: {$versionName}/{$name}",
                        "{$versionName}/{$name}",
                    );
                }
            }
        }

        $manifestPaths = [];
        foreach ($inventory->manifest as $digest => $paths) {
            foreach ($paths as $path) {
                $manifestPaths[$path] = $digest;
            }
        }

        foreach ($onDisk as $relative => $absolute) {
            if (! isset($manifestPaths[$relative])) {
                $report->addError(ErrorCode::E023, "content file not in manifest: {$relative}", $relative);

                continue;
            }

            $actual = $fs->digestFile($absolute, $inventory->digestAlgorithm);
            if (! Digest::equals($actual, $manifestPaths[$relative])) {
                $report->addError(ErrorCode::E092, "content digest mismatch for {$relative}", $relative);
            }
        }

        foreach (array_keys($manifestPaths) as $expected) {
            if (! isset($onDisk[$expected])) {
                $report->addError(ErrorCode::E023, "manifest entry missing on disk: {$expected}", $expected);
            }
        }

        $this->checkStateDigestsInManifest($inventory, $report);
    }

    private function checkStateDigestsInManifest(Inventory $inventory, ValidationReport $report): void
    {
        foreach ($inventory->versions as $versionName => $version) {
            foreach (array_keys($version->state) as $digest) {
                if (! isset($inventory->manifest[$digest])) {
                    $report->addError(
                        ErrorCode::E050,
                        'state digest not in manifest',
                        $versionName,
                    );
                    break;
                }
            }
        }
    }

    private function checkLogicalPathFormat(Inventory $inventory, ValidationReport $report): void
    {
        foreach ($inventory->versions as $versionName => $version) {
            foreach ($version->state as $paths) {
                foreach ($paths as $path) {
                    if (str_starts_with($path, '/')) {
                        $report->addError(
                            ErrorCode::E053,
                            "logical path has leading slash: '{$path}'",
                            $versionName,
                        );

                        continue;
                    }

                    foreach (explode('/', $path) as $segment) {
                        if ($segment === '' || $segment === '.' || $segment === '..') {
                            $report->addError(
                                ErrorCode::E052,
                                "logical path has invalid segment: '{$path}'",
                                $versionName,
                            );

                            continue 2;
                        }
                    }
                }
            }
        }
    }

    private function checkManifestCoverage(Inventory $inventory, ValidationReport $report): void
    {
        $referenced = [];
        foreach ($inventory->versions as $version) {
            foreach (array_keys($version->state) as $digest) {
                $referenced[$digest] = true;
            }
        }

        foreach (array_keys($inventory->manifest) as $digest) {
            if (! isset($referenced[$digest])) {
                $report->addError(
                    ErrorCode::E107,
                    "manifest digest {$digest} is not referenced by any version state",
                );
            }
        }
    }

    private function checkLogicalPathUniqueness(Inventory $inventory, ValidationReport $report): void
    {
        foreach ($inventory->versions as $versionName => $version) {
            $seen = [];
            foreach ($version->state as $paths) {
                foreach ($paths as $p) {
                    if (isset($seen[$p])) {
                        $report->addError(
                            ErrorCode::E095,
                            "logical path '{$p}' appears multiple times in version state",
                            $versionName,
                        );

                        continue;
                    }
                    $seen[$p] = true;
                }
            }
        }
    }

    private function emitWarnings(Inventory $inventory, ValidationReport $report): void
    {
        if ($inventory->digestAlgorithm === DigestAlgorithm::Sha256) {
            $report->addWarning(ErrorCode::W004, 'object uses sha256; sha512 is recommended');
        }

        if (! self::looksLikeUri($inventory->id)) {
            $report->addWarning(ErrorCode::W005, "id '{$inventory->id}' is not a URI");
        }

        foreach ($inventory->versions as $versionName => $version) {
            if ($version->message === null || $version->user === null) {
                $report->addWarning(
                    ErrorCode::W007,
                    'version missing message and/or user block',
                    $versionName,
                );
            }

            if ($version->user !== null) {
                if ($version->user->address === null || $version->user->address === '') {
                    $report->addWarning(ErrorCode::W008, 'user has no address', $versionName);
                } elseif (! self::looksLikeUri($version->user->address)) {
                    $report->addWarning(
                        ErrorCode::W009,
                        "user.address '{$version->user->address}' is not a URI",
                        $versionName,
                    );
                }
            }
        }
    }

    /**
     * @param  list<string>  $versionNames
     */
    private static function detectPaddingWidth(array $versionNames): ?int
    {
        if ($versionNames === []) {
            return null;
        }

        $first = $versionNames[0];
        if (preg_match('/^v(0+)?\d+$/', $first, $matches) !== 1) {
            return null;
        }

        return strlen($first) - 1;
    }

    private static function looksLikeUri(string $value): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $value) === 1;
    }
}
