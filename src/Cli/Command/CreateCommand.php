<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\OcflObject;

final class CreateCommand implements Command
{
    /**
     * @param  list<string>  $args
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public function run(array $args, $stdout, $stderr): int
    {
        $options = Options::parse($args);
        $path = $options->positional[0] ?? null;
        $id = $options->positional[1] ?? null;

        if ($path === null || $id === null) {
            fwrite($stderr, "create: usage: ocfl create <path> <id> [--digest=sha512|sha256]\n");

            return 2;
        }

        $digestValue = $options->value('digest') ?? DigestAlgorithm::Sha512->value;
        $algorithm = DigestAlgorithm::tryFrom($digestValue);
        if ($algorithm === null || ! $algorithm->isPrimary()) {
            $allowed = implode(', ', array_map(
                fn (DigestAlgorithm $a) => $a->value,
                array_filter(DigestAlgorithm::cases(), fn (DigestAlgorithm $a) => $a->isPrimary()),
            ));
            fwrite($stderr, "create: unsupported --digest '{$digestValue}' (must be {$allowed})\n");

            return 2;
        }

        OcflObject::create(path: $path, id: $id, digestAlgorithm: $algorithm);

        fwrite($stdout, "created OCFL object {$id} at {$path}\n");

        return 0;
    }
}
