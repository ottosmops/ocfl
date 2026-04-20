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
        $this->checkFixity($fs, $objectRoot, $inventory, $report);
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

        // Zero-padded and unpadded versions MUST NOT be mixed (spec E013).
        // A name is "padded" iff the number part has a leading zero. v10
        // is unpadded, v01 is padded, v001 is padded.
        $paddedNames = [];
        $unpaddedNames = [];
        foreach (array_merge($versionNames, [$inventory->head]) as $name) {
            $name = (string) $name;
            if (preg_match('/^v(\d+)$/', $name, $matches) !== 1) {
                continue;
            }
            $numberPart = $matches[1];
            if (strlen($numberPart) > 1 && $numberPart[0] === '0') {
                $paddedNames[] = $name;
            } else {
                $unpaddedNames[] = $name;
            }
        }

        if ($paddedNames !== [] && $unpaddedNames !== []) {
            $report->addError(
                ErrorCode::E013,
                'inventory mixes zero-padded and unpadded version numbers ('
                . 'padded: ' . implode(',', array_slice($paddedNames, 0, 3))
                . '; unpadded: ' . implode(',', array_slice($unpaddedNames, 0, 3))
                . ')',
            );
        }

        if ($paddedNames !== []) {
            $report->addWarning(
                ErrorCode::W001,
                "version directory names are zero-padded ({$paddedNames[0]})",
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
        // E023 append-only check: walking versions chronologically, every
        // manifest path seen so far MUST still be present in each subsequent
        // version inventory's manifest.
        $accumulatedPaths = [];
        foreach (array_keys($inventory->versions) as $versionName) {
            $versionInventoryPath = $objectRoot . '/' . (string) $versionName . '/' . Inventory::FILENAME;
            if (! $fs->fileExists($versionInventoryPath)) {
                continue;
            }
            try {
                $vi = InventoryReader::fromFilesystem($fs, $versionInventoryPath);
            } catch (OcflException) {
                continue;
            }
            foreach ($accumulatedPaths as $previousPath) {
                if (! self::manifestHasPath($vi->manifest, $previousPath)) {
                    $report->addError(
                        ErrorCode::E023,
                        "content path '{$previousPath}' present in earlier manifest is missing from version {$versionName} inventory",
                        (string) $versionName,
                    );
                }
            }
            foreach ($vi->manifest as $paths) {
                foreach ($paths as $p) {
                    $accumulatedPaths[$p] = true;
                }
            }
            $accumulatedPaths = array_combine(array_keys($accumulatedPaths), array_keys($accumulatedPaths));
        }

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
                    (string) $versionName,
                );
            }

            // E040: a version directory's own inventory must have head = the
            // version directory's name. Otherwise the version is a lie about
            // what it captures.
            if ($versionInventory->head !== (string) $versionName) {
                $report->addError(
                    ErrorCode::E040,
                    "version inventory head '{$versionInventory->head}' does not match its directory name '{$versionName}'",
                    (string) $versionName,
                );
            }

            // E066: digest algorithm changes across versions are permitted
            // only through a specific rehashing process. If a version's
            // inventory uses a different algorithm than the root, flag it.
            if ($versionInventory->digestAlgorithm !== $inventory->digestAlgorithm) {
                $report->addError(
                    ErrorCode::E066,
                    "version inventory digestAlgorithm '{$versionInventory->digestAlgorithm->value}' differs from root '{$inventory->digestAlgorithm->value}'",
                    (string) $versionName,
                );
            }

            // E092: verify this version inventory's own manifest against
            // files on disk, under its own declared algorithm.
            foreach ($versionInventory->manifest as $digest => $paths) {
                foreach ($paths as $p) {
                    $absolute = $objectRoot . '/' . $p;
                    if (! $fs->fileExists($absolute)) {
                        continue;
                    }
                    if (! in_array($versionInventory->digestAlgorithm->hashAlgorithm(), hash_algos(), true)) {
                        continue;
                    }
                    $actual = $fs->digestFile($absolute, $versionInventory->digestAlgorithm);
                    if (! Digest::equals($actual, $digest)) {
                        $report->addError(
                            ErrorCode::E092,
                            "version {$versionName} inventory manifest digest does not match file at '{$p}'",
                            (string) $versionName,
                        );
                    }
                }
            }

            // E066: the state for a given version must be identical whether
            // read from the root inventory or from that version's own
            // inventory. State is canonical; diverging states after an
            // algorithm change imply a broken rehashing process.
            $rootBlock = $inventory->versions[$versionName] ?? null;
            $verBlock = $versionInventory->versions[$versionName] ?? null;
            if ($rootBlock !== null && $verBlock !== null) {
                if (self::normalisedPaths($rootBlock->state) !== self::normalisedPaths($verBlock->state)) {
                    $report->addError(
                        ErrorCode::E066,
                        "state of version {$versionName} differs between root and version inventory",
                        (string) $versionName,
                    );
                }

                // W011: same idea for metadata (message / user). Metadata
                // mismatches are warnings rather than errors because the
                // state-canonical invariant above doesn't apply.
                $rootMsg = $rootBlock->message ?? '';
                $verMsg = $verBlock->message ?? '';
                $rootUserName = $rootBlock->user?->name;
                $verUserName = $verBlock->user?->name;
                if ($rootMsg !== $verMsg || $rootUserName !== $verUserName) {
                    $report->addWarning(
                        ErrorCode::W011,
                        "version {$versionName} inventory metadata differs from root inventory's record",
                        (string) $versionName,
                    );
                }
            }

            // E023: any content path that ever appeared in a historical
            // manifest MUST still be present in the current root manifest
            // (OCFL manifests are append-only for preservation).
            foreach ($versionInventory->manifest as $digest => $paths) {
                foreach ($paths as $p) {
                    if (! self::manifestHasPath($inventory->manifest, $p)) {
                        $report->addError(
                            ErrorCode::E023,
                            "content path from version inventory missing from root manifest: '{$p}'",
                            (string) $versionName,
                        );
                    }
                }
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
                // E092 covers the same underlying fact (content path in
                // manifest doesn't correspond to an actual file); fixtures
                // encoding either code should satisfy either expectation.
                $report->addError(ErrorCode::E092, "content path in manifest does not exist: {$expected}", $expected);
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

        if (isset($raw['fixity']) && is_array($raw['fixity'])) {
            foreach ($raw['fixity'] as $algorithm => $fixityMap) {
                if (! is_array($fixityMap)) {
                    continue;
                }
                $algoLabel = is_string($algorithm) ? $algorithm : 'unknown';
                $this->checkDuplicateDigestCase(
                    $fixityMap,
                    "fixity.{$algoLabel}",
                    ErrorCode::E097,
                    $report,
                );
            }
        }

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
        if (isset($inventory->versions[$inventory->head])) {
            return;
        }

        $report->addError(
            ErrorCode::E040,
            "head '{$inventory->head}' does not name a version in the inventory",
        );

        // If the head name is a well-formed vN but simply skipped in the
        // versions map, this is also a gap in the version sequence (E010).
        if (preg_match('/^v0*(\d+)$/', $inventory->head) === 1) {
            $report->addError(
                ErrorCode::E010,
                "version sequence is missing entries up to head '{$inventory->head}'",
            );
        }

    }

    private function checkFixity(Filesystem $fs, string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        foreach ($inventory->fixity as $algorithm => $map) {
            // E097: duplicate digests differing only in case.
            $seen = [];
            foreach (array_keys($map) as $digest) {
                $normalised = strtolower($digest);
                if (isset($seen[$normalised])) {
                    $report->addError(
                        ErrorCode::E097,
                        "fixity.{$algorithm} has duplicate digests differing only by case: '{$digest}'",
                    );
                }
                $seen[$normalised] = true;
            }

            foreach ($map as $digest => $paths) {
                foreach ($paths as $path) {
                    // E099/E100: path format in fixity.
                    if ($path === '' || str_starts_with($path, '/')) {
                        $report->addError(ErrorCode::E100, "fixity.{$algorithm} path is absolute: '{$path}'");

                        continue;
                    }
                    foreach (explode('/', $path) as $segment) {
                        if ($segment === '') {
                            $report->addError(ErrorCode::E099, "fixity.{$algorithm} path has empty segment: '{$path}'");

                            continue 2;
                        }
                        if ($segment === '.' || $segment === '..') {
                            $report->addError(ErrorCode::E100, "fixity.{$algorithm} path has dot segment: '{$path}'");

                            continue 2;
                        }
                    }

                    // E093: fixity digest must match the actual file digest
                    // under that algorithm.
                    $absolute = $objectRoot . '/' . $path;
                    if (! $fs->fileExists($absolute)) {
                        continue;
                    }
                    $fixityAlgo = DigestAlgorithm::tryFrom($algorithm);
                    if ($fixityAlgo === null) {
                        continue;
                    }
                    if (! in_array($fixityAlgo->hashAlgorithm(), hash_algos(), true)) {
                        // PHP build lacks this algorithm — skip; a dedicated
                        // fixity verifier would install the missing extension.
                        continue;
                    }
                    $actual = $fs->digestFile($absolute, $fixityAlgo);
                    if (! Digest::equals($actual, $digest)) {
                        $report->addError(
                            ErrorCode::E093,
                            "fixity.{$algorithm} digest mismatch for '{$path}'",
                            $path,
                        );
                    }
                }
            }
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

    /**
     * Project a state map down to a sorted flat set of logical paths, so
     * states can be compared independently of their digest algorithm.
     *
     * @param  array<string, list<string>>  $state
     * @return list<string>
     */
    private static function normalisedPaths(array $state): array
    {
        $paths = [];
        foreach ($state as $list) {
            foreach ($list as $p) {
                $paths[] = $p;
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * @param  array<string, list<string>>  $manifest
     */
    private static function manifestHasPath(array $manifest, string $path): bool
    {
        foreach ($manifest as $paths) {
            if (in_array($path, $paths, true)) {
                return true;
            }
        }

        return false;
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
            $allPaths = [];
            foreach ($version->state as $paths) {
                foreach ($paths as $p) {
                    if (isset($seen[$p])) {
                        $report->addError(
                            ErrorCode::E095,
                            "logical path '{$p}' appears multiple times in version state",
                            (string) $versionName,
                        );

                        continue;
                    }
                    $seen[$p] = true;
                    $allPaths[] = $p;
                }
            }

            // E095 (conflicting): one state path can't be both a file and a
            // directory prefix of another. E.g. `sub-path` vs `sub-path/x`.
            foreach ($allPaths as $a) {
                foreach ($allPaths as $b) {
                    if ($a === $b) {
                        continue;
                    }
                    if (str_starts_with($b, $a . '/')) {
                        $report->addError(
                            ErrorCode::E095,
                            "logical paths conflict: '{$a}' is a prefix directory of '{$b}'",
                            (string) $versionName,
                        );
                        break 2;
                    }
                }
            }
        }
    }

    private function emitWarnings(Filesystem $fs, string $objectRoot, Inventory $inventory, ValidationReport $report): void
    {
        if ($inventory->digestAlgorithm === DigestAlgorithm::Sha256) {
            $report->addWarning(ErrorCode::W004, 'object uses sha256; sha512 is recommended');
        }

        // Also check version inventories — if any version uses sha256
        // (e.g., after an algorithm change), surface W004 for that too.
        foreach (array_keys($inventory->versions) as $versionName) {
            $viPath = $objectRoot . '/' . (string) $versionName . '/' . Inventory::FILENAME;
            if (! $fs->fileExists($viPath)) {
                continue;
            }
            try {
                $vi = InventoryReader::fromFilesystem($fs, $viPath);
            } catch (OcflException) {
                continue;
            }
            if ($vi->digestAlgorithm === DigestAlgorithm::Sha256
                && $inventory->digestAlgorithm !== DigestAlgorithm::Sha256) {
                $report->addWarning(
                    ErrorCode::W004,
                    "version {$versionName} uses sha256; sha512 is recommended",
                    (string) $versionName,
                );
            }
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
            $versionName = (string) $versionName;
            if ($version->message === null || $version->message === '' || $version->user === null) {
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

    private static function looksLikeUri(string $value): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $value) === 1;
    }
}
