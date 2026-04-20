<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Inventory;

use DateTimeImmutable;
use Exception;
use JsonException;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\Filesystem;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;
use RuntimeException;

/**
 * Parses inventory.json into an Inventory value object.
 *
 * Validation here is the minimum required to construct a type-safe domain
 * model (presence of required fields, correct types). Richer structural
 * validation against all OCFL error codes lives in the Validator.
 */
final class InventoryReader
{
    /**
     * Minimum set of required top-level fields per OCFL 1.1 §7.1.
     *
     * @var list<string>
     */
    private const REQUIRED_FIELDS = [
        'id',
        'type',
        'digestAlgorithm',
        'head',
        'manifest',
        'versions',
    ];

    public static function fromFilesystem(Filesystem $fs, string $path): Inventory
    {
        if (! $fs->fileExists($path)) {
            throw new OcflException(ErrorCode::E033, "inventory file not readable: {$path}");
        }

        try {
            $contents = $fs->read($path);
        } catch (RuntimeException $e) {
            throw new OcflException(ErrorCode::E033, "inventory file read failed: {$path}", $e);
        }

        return self::fromString($contents);
    }

    public static function fromString(string $json): Inventory
    {
        try {
            /** @var mixed $raw */
            $raw = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OcflException(ErrorCode::E033, 'inventory is not valid JSON', $e);
        }

        if (! is_array($raw)) {
            throw new OcflException(ErrorCode::E033, 'inventory root must be a JSON object');
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $raw)) {
                throw new OcflException(
                    $field === 'manifest' ? ErrorCode::E041 : ErrorCode::E036,
                    "inventory missing required field '{$field}'",
                );
            }
        }

        return new Inventory(
            id: self::readString($raw, 'id', ErrorCode::E036),
            type: self::readString($raw, 'type', ErrorCode::E038),
            digestAlgorithm: self::readDigestAlgorithm($raw),
            head: self::readString($raw, 'head', ErrorCode::E040),
            contentDirectory: isset($raw['contentDirectory']) && is_string($raw['contentDirectory'])
                ? $raw['contentDirectory']
                : Inventory::DEFAULT_CONTENT_DIRECTORY,
            manifest: self::readDigestMap($raw['manifest'], 'manifest'),
            versions: self::readVersions($raw['versions']),
            fixity: self::readFixity($raw['fixity'] ?? []),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private static function readString(array $raw, string $field, ErrorCode $code): string
    {
        $value = $raw[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new OcflException($code, "inventory field '{$field}' must be a non-empty string");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private static function readDigestAlgorithm(array $raw): DigestAlgorithm
    {
        $value = $raw['digestAlgorithm'] ?? null;

        if (! is_string($value)) {
            throw new OcflException(ErrorCode::E025, 'digestAlgorithm must be a string');
        }

        $algorithm = DigestAlgorithm::tryFrom($value);

        if ($algorithm === null || ! $algorithm->isPrimary()) {
            throw new OcflException(
                ErrorCode::E025,
                "unsupported primary digestAlgorithm '{$value}' (must be sha512 or sha256)",
            );
        }

        return $algorithm;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function readDigestMap(mixed $raw, string $context): array
    {
        if (! is_array($raw)) {
            throw new OcflException(ErrorCode::E034, "{$context} must be a JSON object");
        }

        $out = [];

        foreach ($raw as $digest => $paths) {
            if (! is_string($digest) || ! is_array($paths)) {
                throw new OcflException(ErrorCode::E034, "{$context} entries malformed");
            }

            $normalised = strtolower($digest);
            $pathList = [];

            foreach ($paths as $path) {
                if (! is_string($path)) {
                    throw new OcflException(ErrorCode::E034, "{$context} paths must be strings");
                }
                $pathList[] = $path;
            }

            $out[$normalised] = $pathList;
        }

        return $out;
    }

    /**
     * @return array<string, Version>
     */
    private static function readVersions(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new OcflException(ErrorCode::E036, 'versions must be a non-empty object');
        }

        $out = [];

        foreach ($raw as $name => $block) {
            if (! is_string($name) || ! is_array($block)) {
                throw new OcflException(ErrorCode::E048, 'version entry malformed');
            }

            $out[$name] = self::readVersion($block);
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $block
     */
    private static function readVersion(array $block): Version
    {
        foreach (['created', 'state'] as $required) {
            if (! array_key_exists($required, $block)) {
                throw new OcflException(
                    ErrorCode::E048,
                    "version block missing required field '{$required}'",
                );
            }
        }

        $created = $block['created'];

        if (! is_string($created)) {
            throw new OcflException(ErrorCode::E049, 'version created must be an RFC3339 string');
        }

        // RFC3339 permits arbitrary-precision fractional seconds; PHP's
        // DATE_RFC3339 / DATE_RFC3339_EXTENDED handle 0 / 3 digits only.
        // Validate the shape first, then hand the parsing itself to
        // DateTimeImmutable which accepts the full range.
        $rfc3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';
        if (preg_match($rfc3339, $created) !== 1) {
            throw new OcflException(ErrorCode::E049, "version created not RFC3339: '{$created}'");
        }

        try {
            $parsed = new DateTimeImmutable($created);
        } catch (Exception $e) {
            throw new OcflException(ErrorCode::E049, "version created not RFC3339: '{$created}'", $e);
        }

        $user = null;
        if (isset($block['user'])) {
            if (! is_array($block['user'])) {
                throw new OcflException(ErrorCode::E054, 'version user must be an object');
            }
            $name = $block['user']['name'] ?? null;
            $address = $block['user']['address'] ?? null;

            if (! is_string($name) || $name === '') {
                throw new OcflException(ErrorCode::E054, 'version user.name must be a non-empty string');
            }

            $user = new User(
                name: $name,
                address: is_string($address) ? $address : null,
            );
        }

        $message = $block['message'] ?? null;

        return new Version(
            created: $parsed,
            state: self::readDigestMap($block['state'], 'state'),
            message: is_string($message) ? $message : null,
            user: $user,
        );
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private static function readFixity(mixed $raw): array
    {
        if ($raw === []) {
            return [];
        }

        if (! is_array($raw)) {
            throw new OcflException(ErrorCode::E034, 'fixity must be a JSON object');
        }

        $out = [];

        foreach ($raw as $algorithm => $map) {
            if (! is_string($algorithm)) {
                throw new OcflException(ErrorCode::E034, 'fixity keys must be strings');
            }

            $out[$algorithm] = self::readDigestMap($map, "fixity.{$algorithm}");
        }

        return $out;
    }
}
