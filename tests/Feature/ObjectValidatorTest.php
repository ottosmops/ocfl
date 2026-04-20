<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\ObjectValidator;

/**
 * @return array<string, array{0: string, 1: string}>
 */
function allGoodFixtures(): array
{
    $base = __DIR__ . '/../fixtures/ocfl/1.1/good-objects';
    $dirs = array_filter(
        scandir($base) ?: [],
        fn (string $d) => $d !== '.' && $d !== '..' && is_dir("{$base}/{$d}"),
    );

    $out = [];
    foreach ($dirs as $name) {
        $out[$name] = [$name, "{$base}/{$name}"];
    }

    return $out;
}

it('validates every good-object fixture with no errors', function (string $name, string $path): void {
    $report = (new ObjectValidator())->validate($path);

    $messages = implode(
        '; ',
        array_map(fn ($e) => $e->code->value . ': ' . $e->message, $report->errors()),
    );

    expect($report->isValid())->toBeTrue("fixture '{$name}' emitted errors: {$messages}");
})->with(allGoodFixtures());

function badFixturePath(string $name): string
{
    return __DIR__ . '/../fixtures/ocfl/1.1/bad-objects/' . $name;
}

it('rejects bad-object fixtures with the expected error codes', function (string $fixture, ErrorCode $expectedCode): void {
    $report = (new ObjectValidator())->validate(badFixturePath($fixture));

    expect($report->isValid())->toBeFalse()
        ->and($report->hasError($expectedCode))->toBeTrue(
            "expected {$expectedCode->value} in {$fixture}, got: "
            . implode(',', array_map(fn ($c) => $c->value, $report->errorCodes())),
        );
})->with([
    ['E001_extra_file_in_root', ErrorCode::E001],
    ['E001_extra_dir_in_root', ErrorCode::E001],
    ['E003_no_decl', ErrorCode::E003],
    ['E036_no_id', ErrorCode::E036],
    ['E036_no_head', ErrorCode::E036],
    ['E041_no_manifest', ErrorCode::E041],
    ['E025_wrong_digest_algorithm', ErrorCode::E025],
    ['E058_no_sidecar', ErrorCode::E058],
    ['E060_E064_root_inventory_digest_mismatch', ErrorCode::E060],
    ['E063_no_inv', ErrorCode::E063],
    ['E040_head_not_most_recent', ErrorCode::E040],
    ['E067_file_in_extensions_dir', ErrorCode::E067],
    ['E092_content_file_digest_mismatch', ErrorCode::E092],
    ['E050_state_digest_not_in_manifest', ErrorCode::E050],
    ['E095_non_unique_logical_paths', ErrorCode::E095],
]);

function warnFixturePath(string $name): string
{
    return __DIR__ . '/../fixtures/ocfl/1.1/warn-objects/' . $name;
}

it('emits the expected warnings on warn-object fixtures', function (string $fixture, ErrorCode $expectedWarning): void {
    $report = (new ObjectValidator())->validate(warnFixturePath($fixture));

    expect($report->hasWarning($expectedWarning))->toBeTrue(
        "expected warning {$expectedWarning->value} in {$fixture}, got: "
        . implode(',', array_map(fn ($c) => $c->value, $report->warningCodes())),
    );
})->with([
    ['W001_zero_padded_versions', ErrorCode::W001],
    ['W004_uses_sha256', ErrorCode::W004],
    ['W005_id_not_uri', ErrorCode::W005],
    ['W007_no_message_or_user', ErrorCode::W007],
    ['W008_user_no_address', ErrorCode::W008],
    ['W009_user_address_not_uri', ErrorCode::W009],
    ['W010_no_version_inventory', ErrorCode::W010],
]);
