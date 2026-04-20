<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Cli\Command\Options;

test('parses positional and --json together', function (): void {
    $o = Options::parse(['a', '--json', 'b']);

    expect($o->positional)->toBe(['a', 'b'])
        ->and($o->json)->toBeTrue()
        ->and($o->help)->toBeFalse();
});

test('--help and -h both set the help flag', function (): void {
    expect(Options::parse(['--help'])->help)->toBeTrue()
        ->and(Options::parse(['-h'])->help)->toBeTrue();
});

test('parses --key=value into the values map', function (): void {
    $o = Options::parse(['--message=Initial import', '--user=Alice']);

    expect($o->value('message'))->toBe('Initial import')
        ->and($o->value('user'))->toBe('Alice')
        ->and($o->value('absent'))->toBeNull()
        ->and($o->value('absent', 'fallback'))->toBe('fallback');
});

test('later --key=value wins on repeat', function (): void {
    expect(Options::parse(['--x=1', '--x=2'])->value('x'))->toBe('2');
});

test('unknown flag without an = is treated as positional', function (): void {
    // Preserves future-flag-friendliness: callers that don't yet know a
    // flag just see it as an extra positional arg rather than a crash.
    $o = Options::parse(['--unknown', 'real']);

    expect($o->positional)->toBe(['--unknown', 'real']);
});
