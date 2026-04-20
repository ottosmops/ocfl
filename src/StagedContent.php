<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl;

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

    public function digest(DigestAlgorithm $algorithm): string
    {
        return $this->sourcePath !== null
            ? Digest::ofFile($this->sourcePath, $algorithm)
            : Digest::ofString($this->inlineBytes ?? '', $algorithm);
    }

    public function writeTo(string $destination): void
    {
        if ($this->sourcePath !== null) {
            if (! copy($this->sourcePath, $destination)) {
                throw new RuntimeException("failed to copy {$this->sourcePath} to {$destination}");
            }

            return;
        }

        if (file_put_contents($destination, $this->inlineBytes ?? '') === false) {
            throw new RuntimeException("failed to write inline bytes to {$destination}");
        }
    }
}
