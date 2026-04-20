<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

use Ottosmops\Ocfl\Storage\StorageRoot;

final class ListCommand implements Command
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

        if ($path === null) {
            fwrite($stderr, "list: missing <storage-root>\n");

            return 2;
        }

        $ids = StorageRoot::open($path)->listObjects();
        sort($ids);

        if ($options->json) {
            fwrite($stdout, json_encode($ids, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

            return 0;
        }

        foreach ($ids as $id) {
            fwrite($stdout, $id . "\n");
        }

        return 0;
    }
}
