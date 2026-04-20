<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

use Ottosmops\Ocfl\Filesystem\Filesystem;
use RuntimeException;

/**
 * A pending content addition in a VersionBuilder, either referenced from a
 * source file on disk or supplied inline as bytes.
 */
final readonly class StagedContent
{
    public function __construct(
        private ?string $sourcePath = null,
        private ?string $inlineBytes = null,
    ) {
        if ($sourcePath === null && $inlineBytes === null) {
            throw new RuntimeException('StagedContent needs either sourcePath or inlineBytes');
        }
    }

    public function digest(Filesystem $fs, DigestAlgorithm $algorithm): string
    {
        return $this->sourcePath !== null
            ? $fs->digestFile($this->sourcePath, $algorithm)
            : Digest::ofString($this->inlineBytes ?? '', $algorithm);
    }

    public function writeTo(Filesystem $fs, string $destination): void
    {
        if ($this->sourcePath !== null) {
            $fs->copy($this->sourcePath, $destination);

            return;
        }

        $fs->write($destination, $this->inlineBytes ?? '');
    }
}
