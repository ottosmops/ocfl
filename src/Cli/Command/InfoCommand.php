<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

use Ottosmops\Ocfl\OcflObject;

final class InfoCommand implements Command
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
            fwrite($stderr, "info: missing <path>\n");

            return 2;
        }

        $object = OcflObject::open($path);

        if ($options->json) {
            fwrite($stdout, json_encode([
                'id' => $object->id(),
                'head' => $object->head(),
                'digestAlgorithm' => $object->inventory->digestAlgorithm->value,
                'contentDirectory' => $object->inventory->contentDirectory,
                'versions' => $object->versionNames(),
                'path' => $object->path,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return 0;
        }

        $versions = implode(', ', $object->versionNames());
        fwrite($stdout, <<<OUT
        OCFL object at {$object->path}
          id:              {$object->id()}
          head:            {$object->head()}
          digestAlgorithm: {$object->inventory->digestAlgorithm->value}
          contentDir:      {$object->inventory->contentDirectory}
          versions:        {$versions}

        OUT);

        return 0;
    }
}
