<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Validation;

use FilesystemIterator;
use Ottosmops\Ocfl\Digest;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Inventory\Inventory;
use Ottosmops\Ocfl\Inventory\InventoryReader;
use Ottosmops\Ocfl\Inventory\InventorySidecar;
use Ottosmops\Ocfl\Namaste;
use Ottosmops\Ocfl\NamasteType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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

    public function validate(string $objectRoot): ValidationReport
    {
        $report = new ValidationReport();

        if (! is_dir($objectRoot)) {
            $report->addError(ErrorCode::E003, "object root does not exist: {$objectRoot}");

            return $report;
        }

        if ((new FilesystemIterator($objectRoot))->valid() === false) {
            $report->addError(ErrorCode::E003, 'object root is empty');

            return $report;
        }

        $this->checkNamaste($objectRoot, $report);
        $inventory = $this->loadAndValidateRootInventory($objectRoot, $report);

        if ($inventory === null) {
            return $report;
        }

        $this->checkRootLayout($objectRoot, $inventory, $report);
        $this->checkVersionDirectories($objectRoot, $inventory, $report);
        $this->checkVersionInventories($objectRoot, $inventory, $report);
        $this->checkContentFilesAgainstManifest($objectRoot, $inventory, $report);
        $this->checkLogicalPathFormat($inventory, $report);
        $this->checkLogicalPathUniqueness($inventory, $report);
        $this->checkManifestCoverage($inventory, $report);
        $this->emitWarnings($objectRoot, $inventory, $report);

        return $report;
    }

    private function checkNamaste(string $objectRoot, ValidationReport $report): void
    {
        try {
            $namaste = Namaste::find($objectRoot);
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

    private function loadAndValidateRootInventory(string $objectRoot, ValidationReport $report): ?Inventory
    {
        $inventoryPath = $objectRoot . DIRECTORY_SEPARATOR . Inventory::FILENAME;

        if (! is_file($inventoryPath)) {
            $report->addError(ErrorCode::E063, 'root inventory.json missing');

            return null;
        }

        try {
            $inventory = InventoryReader::fromFile($inventoryPath);
        } catch (OcflException $e) {
            $report->addError($e->errorCode, $e->getMessage());

            return null;
        }

        $this->checkSidecar($objectRoot, $inventory, $report);

        return $inventory;
    }

    private function checkSidecar(string $directory, Inventory $inventory, ValidationReport $report): void
    {
        $sidecarPath = $directory . DIRECTORY_SEPARATOR . InventorySidecar::filename($inventory->digestAlgorithm);

        if (! is_file($sidecarPath)) {
            $report->addError(ErrorCode::E058, "sidecar missing: {$sidecarPath}");

            return;
        }

        try {
            if (! InventorySidecar::verify($directory, $inventory->digestAlgorithm)) {
                $report->addError(ErrorCode::E060, 'sidecar digest does not match inventory.json', $sidecarPath);
            }
        } catch (OcflException $e) {
            $report->addError($e->errorCode, $e->getMessage(), $sidecarPath);
        }
    }

    private function checkRootLayout(string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        $versionPattern = '/^v\d+$/';
        $sidecarFilename = InventorySidecar::filename($inventory->digestAlgorithm);

        foreach (new FilesystemIterator($objectRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            $name = $entry->getFilename();

            if (in_array($name, self::ALLOWED_ROOT_NAMES, true)) {
                continue;
            }

            if ($name === $sidecarFilename) {
                continue;
            }

            if ($entry->isDir() && preg_match($versionPattern, $name) === 1) {
                continue;
            }

            if ($entry->isFile() && $name === $sidecarFilename) {
                continue;
            }

            $report->addError(
                ErrorCode::E001,
                $entry->isDir()
                    ? "unexpected directory in object root: {$name}"
                    : "unexpected file in object root: {$name}",
                $name,
            );
        }

        $extensionsPath = $objectRoot . DIRECTORY_SEPARATOR . 'extensions';
        if (is_dir($extensionsPath)) {
            foreach (new FilesystemIterator($extensionsPath, FilesystemIterator::SKIP_DOTS) as $ext) {
                /** @var SplFileInfo $ext */
                if (! $ext->isDir()) {
                    $report->addError(ErrorCode::E067, "extensions/ contains non-directory: {$ext->getFilename()}");
                }
            }
        }
    }

    private function checkVersionDirectories(string $objectRoot, Inventory $inventory, ValidationReport $report): void
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
            $versionPath = $objectRoot . DIRECTORY_SEPARATOR . $versionName;
            if (! is_dir($versionPath)) {
                $report->addError(ErrorCode::E010, "version directory missing on disk: {$versionName}");

                continue;
            }

            $versionInventoryPath = $versionPath . DIRECTORY_SEPARATOR . Inventory::FILENAME;
            if (! is_file($versionInventoryPath)) {
                $report->addWarning(ErrorCode::W010, 'version directory has no inventory.json', $versionName);
            }
        }
    }

    private function checkContentFilesAgainstManifest(string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        $contentDir = $inventory->contentDirectory;
        $onDisk = [];

        foreach (array_keys($inventory->versions) as $versionName) {
            $versionContent = $objectRoot . DIRECTORY_SEPARATOR . $versionName . DIRECTORY_SEPARATOR . $contentDir;
            if (! is_dir($versionContent)) {
                continue;
            }

            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($versionContent, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iter as $file) {
                /** @var SplFileInfo $file */
                if (! $file->isFile()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($objectRoot) + 1);
                $onDisk[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $file->getPathname();
            }

            // Flag files under the version directory but outside contentDirectory.
            $versionPath = $objectRoot . DIRECTORY_SEPARATOR . $versionName;
            foreach (new FilesystemIterator($versionPath, FilesystemIterator::SKIP_DOTS) as $entry) {
                /** @var SplFileInfo $entry */
                $name = $entry->getFilename();
                if ($name === $contentDir) {
                    continue;
                }
                if ($name === Inventory::FILENAME || $name === InventorySidecar::filename($inventory->digestAlgorithm)) {
                    continue;
                }
                if ($entry->isDir()) {
                    // Extra directory within version that isn't contentDirectory.
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

            $actual = Digest::ofFile($absolute, $inventory->digestAlgorithm);
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

    private function checkVersionInventories(string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        foreach (array_keys($inventory->versions) as $versionName) {
            $versionInventoryPath = $objectRoot . DIRECTORY_SEPARATOR . $versionName . DIRECTORY_SEPARATOR . Inventory::FILENAME;
            if (! is_file($versionInventoryPath)) {
                continue;
            }

            try {
                $versionInventory = InventoryReader::fromFile($versionInventoryPath);
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

        $headVersionInventoryPath = $objectRoot . DIRECTORY_SEPARATOR . $inventory->head
            . DIRECTORY_SEPARATOR . Inventory::FILENAME;

        if (is_file($headVersionInventoryPath)) {
            $rootJson = (string) file_get_contents($objectRoot . DIRECTORY_SEPARATOR . Inventory::FILENAME);
            $headJson = (string) file_get_contents($headVersionInventoryPath);

            if ($rootJson !== $headJson) {
                $report->addError(
                    ErrorCode::E064,
                    'root inventory does not match head version inventory byte-for-byte',
                    $inventory->head,
                );
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

    private function emitWarnings(string $objectRoot, Inventory $inventory, ValidationReport $report): void
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
