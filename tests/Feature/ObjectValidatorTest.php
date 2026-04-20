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
    ['E037_inconsistent_id', ErrorCode::E037],
    ['E049_created_no_timezone', ErrorCode::E049],
    ['E049_created_not_to_seconds', ErrorCode::E049],
    ['E053_E052_invalid_logical_paths', ErrorCode::E053],
    ['E103_older_spec_v2', ErrorCode::E103],
    ['E107_file_in_manifest_not_used', ErrorCode::E107],
    ['E064_different_root_and_latest_inventories', ErrorCode::E064],
    ['E001_invalid_version_format', ErrorCode::E001],
    ['E001_v2_file_in_root', ErrorCode::E001],
    ['E007_bad_declaration_contents', ErrorCode::E007],
    ['E017_invalid_content_dir', ErrorCode::E017],
    ['E019_inconsistent_content_dir', ErrorCode::E019],
    ['E040_wrong_head_doesnt_exist', ErrorCode::E040],
    ['E040_wrong_head_format', ErrorCode::E040],
    ['E046_root_not_most_recent', ErrorCode::E046],
    ['E050_manifest_digest_wrong_case', ErrorCode::E050],
    ['E060_version_inventory_digest_mismatch', ErrorCode::E060],
    ['E096_manifest_duplicate_digests', ErrorCode::E096],
    ['E101_non_unique_content_paths', ErrorCode::E101],
    ['E100_E099_manifest_invalid_content_paths', ErrorCode::E100],
    ['E015_content_not_in_content_dir', ErrorCode::E015],
    ['E003_E063_empty', ErrorCode::E003],
    ['E010_skipped_versions', ErrorCode::E010],
    ['E010_missing_versions', ErrorCode::E010],
    ['E023_extra_file', ErrorCode::E023],
    ['E023_old_manifest_missing_entries', ErrorCode::E023],
    ['E049_E050_E054_bad_version_block_values', ErrorCode::E049],
    ['E061_invalid_sidecar', ErrorCode::E061],
    ['E092_E093_content_path_does_not_exist', ErrorCode::E092],
    ['E093_fixity_digest_mismatch', ErrorCode::E093],
    ['E095_conflicting_logical_paths', ErrorCode::E095],
    ['E097_fixity_duplicate_digests', ErrorCode::E097],
    ['E100_E099_fixity_invalid_content_paths', ErrorCode::E100],
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
    ['W002_extra_dir_in_version_dir', ErrorCode::W002],
    ['W013_unregistered_extension', ErrorCode::W013],
]);
