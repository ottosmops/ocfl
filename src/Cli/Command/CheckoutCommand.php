<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

use Ottosmops\Ocfl\OcflObject;

final class CheckoutCommand implements Command
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
        $target = $options->positional[1] ?? null;

        if ($path === null || $target === null) {
            fwrite($stderr, "checkout: usage: ocfl checkout <path> <target> [--version=v1]\n");

            return 2;
        }

        $object = OcflObject::open($path);
        $version = $options->value('version') ?? $object->head();
        $object->checkout($target, $version);

        fwrite($stdout, "checked out {$version} from {$path} to {$target}\n");

        return 0;
    }
}
