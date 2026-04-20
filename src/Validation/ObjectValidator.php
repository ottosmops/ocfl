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

        $this->checkInventoryRawJson($fs, $objectRoot, $report);
        $this->checkContentDirectoryName($inventory, $report);
        $this->checkHeadPresence($inventory, $report);
        $this->checkRootLayout($fs, $objectRoot, $inventory, $report);
        $this->checkVersionDirectories($fs, $objectRoot, $inventory, $report);
        $this->checkVersionInventories($fs, $objectRoot, $inventory, $report);
        $this->checkManifestContentPaths($inventory, $report);
        $this->checkContentFilesAgainstManifest($fs, $objectRoot, $inventory, $report);
        $this->checkLogicalPathFormat($inventory, $report);
        $this->checkLogicalPathUniqueness($inventory, $report);
        $this->checkManifestCoverage($inventory, $report);
        $this->emitWarnings($fs, $objectRoot, $inventory, $report);

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

        // E046: every vN directory on disk must be in the versions map.
        foreach ($fs->listDirectory($objectRoot) as $name) {
            if (preg_match('/^v\d+$/', $name) !== 1) {
                continue;
            }
            if (! $fs->directoryExists($objectRoot . '/' . $name)) {
                continue;
            }
            if (! isset($inventory->versions[$name])) {
                $report->addError(
                    ErrorCode::E046,
                    "version directory {$name} exists on disk but is not in the inventory's versions map",
                    $name,
                );
            }
        }

        foreach ($versionNames as $versionName) {
            $nameStr = (string) $versionName;
            if (preg_match('/^v\d+$/', $nameStr) !== 1) {
                $report->addError(ErrorCode::E011, "malformed version name '{$nameStr}'");
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
            $nameStr = (string) $name;
            if (preg_match('/^v0*(\d+)$/', $nameStr, $matches) !== 1) {
                continue;
            }
            $number = (int) $matches[1];
            if ($number !== $expected) {
                $report->addError(
                    ErrorCode::E010,
                    "version sequence broken; expected v{$expected} but got {$nameStr}",
                );
                break;
            }
            $expected++;
        }

        $last = $versionNames === [] ? null : (string) $versionNames[array_key_last($versionNames)];
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
            $versionDir = $objectRoot . '/' . $versionName;
            $versionInventoryPath = $versionDir . '/' . Inventory::FILENAME;
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

            if ($versionInventory->contentDirectory !== $inventory->contentDirectory) {
                $report->addError(
                    ErrorCode::E019,
                    "version inventory contentDirectory '{$versionInventory->contentDirectory}' differs from root '{$inventory->contentDirectory}'",
                    $versionName,
                );
            }

            // E060 within each version directory: its own sidecar must match
            // its own inventory.json.
            $sidecarPath = $versionDir . '/' . InventorySidecar::filename($versionInventory->digestAlgorithm);
            if ($fs->fileExists($sidecarPath)) {
                try {
                    if (! InventorySidecar::verify($fs, $versionDir, $versionInventory->digestAlgorithm)) {
                        $report->addError(
                            ErrorCode::E060,
                            'version inventory sidecar digest does not match inventory.json',
                            $versionName,
                        );
                    }
                } catch (OcflException $e) {
                    $report->addError($e->errorCode, $e->getMessage(), $versionName);
                }
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

    private function checkInventoryRawJson(Filesystem $fs, string $objectRoot, ValidationReport $report): void
    {
        $path = $objectRoot . '/' . Inventory::FILENAME;
        if (! $fs->fileExists($path)) {
            return;
        }

        try {
            /** @var mixed $raw */
            $raw = json_decode($fs->read($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (! is_array($raw)) {
            return;
        }

        $manifestRaw = is_array($raw['manifest'] ?? null) ? $raw['manifest'] : [];

        $this->checkDuplicateDigestCase($manifestRaw, 'manifest', ErrorCode::E096, $report);

        if (isset($raw['versions']) && is_array($raw['versions'])) {
            foreach ($raw['versions'] as $versionName => $block) {
                if (! is_array($block) || ! isset($block['state']) || ! is_array($block['state'])) {
                    continue;
                }
                $context = is_string($versionName) ? "versions.{$versionName}.state" : 'state';
                $this->checkDuplicateDigestCase($block['state'], $context, ErrorCode::E095, $report);

                // E050: a state digest must match a manifest digest verbatim
                // (same case), not just case-insensitively.
                foreach (array_keys($block['state']) as $stateDigest) {
                    if (! is_string($stateDigest)) {
                        continue;
                    }
                    if (isset($manifestRaw[$stateDigest])) {
                        continue;
                    }
                    // Exists case-insensitively but not verbatim?
                    foreach (array_keys($manifestRaw) as $manifestDigest) {
                        if (is_string($manifestDigest)
                            && strcasecmp($manifestDigest, $stateDigest) === 0) {
                            $report->addError(
                                ErrorCode::E050,
                                "state digest case differs from manifest entry: '{$stateDigest}'",
                                is_string($versionName) ? $versionName : '',
                            );
                            continue 2;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $map
     */
    private function checkDuplicateDigestCase(array $map, string $context, ErrorCode $dupCode, ValidationReport $report): void
    {
        $seen = [];
        foreach (array_keys($map) as $digest) {
            if (! is_string($digest)) {
                continue;
            }
            $normalised = strtolower($digest);
            if (isset($seen[$normalised])) {
                $report->addError($dupCode, "{$context} has duplicate digests differing only by case: '{$digest}'");
            }
            $seen[$normalised] = true;
        }
    }

    private function checkContentDirectoryName(Inventory $inventory, ValidationReport $report): void
    {
        $dir = $inventory->contentDirectory;

        if ($dir === '' || str_contains($dir, '/') || $dir === '.' || $dir === '..') {
            $report->addError(ErrorCode::E017, "invalid contentDirectory: '{$dir}'");
        }
    }

    private function checkHeadPresence(Inventory $inventory, ValidationReport $report): void
    {
        if (! isset($inventory->versions[$inventory->head])) {
            $report->addError(
                ErrorCode::E040,
                "head '{$inventory->head}' does not name a version in the inventory",
            );
        }
    }

    private function checkManifestContentPaths(Inventory $inventory, ValidationReport $report): void
    {
        $seenPaths = [];

        foreach ($inventory->manifest as $digest => $paths) {
            $localSeen = [];
            foreach ($paths as $path) {
                if ($path === '' || str_starts_with($path, '/')) {
                    $report->addError(ErrorCode::E100, "manifest content path is absolute: '{$path}'");

                    continue;
                }

                $segments = explode('/', $path);
                $hasBadSegment = false;
                foreach ($segments as $segment) {
                    if ($segment === '') {
                        $report->addError(ErrorCode::E099, "manifest content path has empty segment: '{$path}'");
                        $hasBadSegment = true;
                        break;
                    }
                    if ($segment === '..') {
                        $report->addError(ErrorCode::E100, "manifest content path has '..' segment: '{$path}'");
                        $hasBadSegment = true;
                        break;
                    }
                    if ($segment === '.') {
                        $report->addError(ErrorCode::E099, "manifest content path has '.' segment: '{$path}'");
                        $hasBadSegment = true;
                        break;
                    }
                }

                if ($hasBadSegment) {
                    continue;
                }

                if (isset($localSeen[$path])) {
                    $report->addError(ErrorCode::E101, "manifest content path appears twice under same digest: '{$path}'");
                } else {
                    $localSeen[$path] = true;
                }

                if (isset($seenPaths[$path])) {
                    $report->addError(ErrorCode::E101, "manifest content path appears under multiple digests: '{$path}'");
                } else {
                    $seenPaths[$path] = $digest;
                }

                // E015: must sit under "<versionName>/<contentDirectory>/".
                $expectedPrefix = self::versionPrefix($path, $inventory->contentDirectory);
                if ($expectedPrefix === null) {
                    $report->addError(
                        ErrorCode::E015,
                        "manifest content path not under a version's contentDirectory: '{$path}'",
                    );
                }
            }
        }
    }

    private static function versionPrefix(string $path, string $contentDirectory): ?string
    {
        $segments = explode('/', $path);
        if (count($segments) < 3) {
            return null;
        }
        if (preg_match('/^v\d+$/', $segments[0]) !== 1) {
            return null;
        }
        if ($segments[1] !== $contentDirectory) {
            return null;
        }

        return $segments[0] . '/' . $contentDirectory;
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

    private function emitWarnings(Filesystem $fs, string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        if ($inventory->digestAlgorithm === DigestAlgorithm::Sha256) {
            $report->addWarning(ErrorCode::W004, 'object uses sha256; sha512 is recommended');
        }

        // W002: extra directory (not "contentDirectory") inside a version dir.
        foreach (array_keys($inventory->versions) as $versionName) {
            $versionPath = $objectRoot . '/' . $versionName;
            if (! $fs->directoryExists($versionPath)) {
                continue;
            }
            foreach ($fs->listDirectory($versionPath) as $entry) {
                if ($entry === $inventory->contentDirectory) {
                    continue;
                }
                if ($entry === Inventory::FILENAME) {
                    continue;
                }
                if ($entry === InventorySidecar::filename($inventory->digestAlgorithm)) {
                    continue;
                }
                if ($fs->directoryExists($versionPath . '/' . $entry)) {
                    $report->addWarning(
                        ErrorCode::W002,
                        "extra directory in version {$versionName}: {$entry}",
                        $versionName,
                    );
                }
            }
        }

        // W013: extensions/ must contain only registered community extensions.
        $extensionsPath = $objectRoot . '/extensions';
        if ($fs->directoryExists($extensionsPath)) {
            foreach ($fs->listDirectory($extensionsPath) as $ext) {
                if ($fs->directoryExists($extensionsPath . '/' . $ext)
                    && preg_match('/^\d{4}-/', $ext) !== 1) {
                    $report->addWarning(
                        ErrorCode::W013,
                        "extension '{$ext}' is not a registered community extension (NNNN- prefix)",
                    );
                }
            }
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

        $first = (string) $versionNames[0];
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
