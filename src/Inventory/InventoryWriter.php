<?php

declare(strict_types=1);

namespace Ottosmops\Ocfl\Inventory;

/**
 * Serialises an Inventory to a deterministic JSON representation.
 *
 * Deterministic output is important so storage-level byte-comparison of
 * inventories remains reliable (snapshot tests, dedup, audit). Strategy:
 *
 *   - All map keys are emitted in ascending order.
 *   - Arrays of strings preserve their list order.
 *   - Default contentDirectory ("content") is omitted.
 *   - Null / empty optional fields are omitted.
 *   - created timestamps are normalised to RFC3339 UTC with Z suffix.
 *   - Output ends with a trailing newline.
 */
final class InventoryWriter
{
    public static function toJson(Inventory $inventory): string
    {
        $data = self::toArray($inventory);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $json . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Inventory $inventory): array
    {
        $out = [
            'digestAlgorithm' => $inventory->digestAlgorithm->value,
            'head' => $inventory->head,
            'id' => $inventory->id,
            'manifest' => self::sortedDigestMap($inventory->manifest),
            'type' => $inventory->type,
            'versions' => self::versionsToArray($inventory->versions),
        ];

        if ($inventory->contentDirectory !== Inventory::DEFAULT_CONTENT_DIRECTORY) {
            $out['contentDirectory'] = $inventory->contentDirectory;
        }

        if ($inventory->fixity !== []) {
            $fixity = [];
            foreach ($inventory->fixity as $algorithm => $map) {
                $fixity[$algorithm] = self::sortedDigestMap($map);
            }
            ksort($fixity);
            $out['fixity'] = $fixity;
        }

        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $map
     * @return array<string, list<string>>
     */
    private static function sortedDigestMap(array $map): array
    {
        ksort($map);

        return $map;
    }

    /**
     * @param  array<string, Version>  $versions
     * @return array<string, array<string, mixed>>
     */
    private static function versionsToArray(array $versions): array
    {
        $out = [];

        foreach ($versions as $name => $version) {
            $block = [
                'created' => self::formatTimestamp($version->created),
                'state' => self::sortedDigestMap($version->state),
            ];

            if ($version->message !== null) {
                $block['message'] = $version->message;
            }

            if ($version->user !== null) {
                $user = ['name' => $version->user->name];
                if ($version->user->address !== null) {
                    $user['address'] = $version->user->address;
                }
                ksort($user);
                $block['user'] = $user;
            }

            ksort($block);
            $out[$name] = $block;
        }

        return $out;
    }

    private static function formatTimestamp(\DateTimeImmutable $created): string
    {
        return $created->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
