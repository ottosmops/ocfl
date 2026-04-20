<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Inventory;

use Ottosmops\Ocfl\DigestAlgorithm;

/**
 * Parsed, immutable representation of an OCFL 1.1 inventory.json (spec §7).
 */
final readonly class Inventory
{
    public const TYPE = 'https://ocfl.io/1.1/spec/#inventory';

    public const FILENAME = 'inventory.json';

    public const DEFAULT_CONTENT_DIRECTORY = 'content';

    /**
     * @param  array<string, list<string>>  $manifest  digest → content paths
     * @param  array<string, Version>  $versions  version name → Version
     * @param  array<string, array<string, list<string>>>  $fixity  algorithm → digest → paths
     */
    public function __construct(
        public string $id,
        public string $type,
        public DigestAlgorithm $digestAlgorithm,
        public string $head,
        public string $contentDirectory,
        public array $manifest,
        public array $versions,
        public array $fixity = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function versionSequence(): array
    {
        return array_keys($this->versions);
    }
}
