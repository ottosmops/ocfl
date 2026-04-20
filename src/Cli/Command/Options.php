<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Cli\Command;

/**
 * Tiny argv parser split between positional args, boolean flags and
 * `--key=value` options. No short-option chaining or `--key value` splits.
 */
final readonly class Options
{
    /**
     * @param  list<string>  $positional
     * @param  array<string, string>  $values  --key=value pairs (last wins)
     */
    public function __construct(
        public array $positional,
        public array $values,
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
        $values = [];
        $json = false;
        $help = false;

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $json = true;

                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $help = true;

                continue;
            }
            if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $values[$key] = $value;

                continue;
            }
            $positional[] = $arg;
        }

        return new self($positional, $values, $json, $help);
    }

    public function value(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }
}
