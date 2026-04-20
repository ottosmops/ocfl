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

        $algorithm = DigestAlgorithm::tryFrom($options->value('digest', 'sha512') ?? 'sha512');
        if ($algorithm === null || ! $algorithm->isPrimary()) {
            fwrite($stderr, "create: unsupported --digest (must be sha512 or sha256)\n");

            return 2;
        }

        OcflObject::create(path: $path, id: $id, digestAlgorithm: $algorithm);

        fwrite($stdout, "created OCFL object {$id} at {$path}\n");

        return 0;
    }
}
