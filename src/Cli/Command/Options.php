<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

/**
 * Tiny argv parser split between `--flag` options and positional args.
 *
 * Only --json and --help are recognised as option flags today; everything
 * else is treated as positional. No short-option chaining or =value syntax.
 */
final readonly class Options
{
    /**
     * @param  list<string>  $positional
     */
    public function __construct(
        public array $positional,
        public bool $json,
        public bool $help,
    ) {
    }

    /**
     * @param  list<string>  $args
     */
    public static function parse(array $args): self
    {
        $positional = [];
        $json = false;
        $help = false;

        foreach ($args as $arg) {
            match (true) {
                $arg === '--json' => $json = true,
                $arg === '--help' || $arg === '-h' => $help = true,
                default => $positional[] = $arg,
            };
        }

        return new self($positional, $json, $help);
    }
}
