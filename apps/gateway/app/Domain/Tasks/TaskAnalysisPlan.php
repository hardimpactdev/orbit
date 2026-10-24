<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Support\StrictJson;
use InvalidArgumentException;

/** The native typed manifest contract. Also loaded by the fixed repository adapter. */
final readonly class TaskAnalysisPlan
{
    public const int ManifestBytes = 32768;

    public const int RequestBytes = 49152;

    public const string DigestPattern = '/\A[a-f0-9]{64}\z/D';

    /** @param array<string, mixed> $manifest
     * @param  list<string>  $paths
     */
    private function __construct(public array $manifest, public array $paths, public string $canonical, public string $sha256) {}

    public static function parse(mixed $value, string $digest): self
    {
        $plan = StrictJson::fields($value, ['id', 'revision', 'source', 'asserted_by', 'complete', 'paths']);
        if (! is_string($plan['id']) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $plan['id']) !== 1
            || ! is_int($plan['revision']) || $plan['revision'] < 1 || $plan['revision'] > 9007199254740991
            || ! is_string($plan['asserted_by']) || trim($plan['asserted_by']) === '' || mb_strlen($plan['asserted_by']) > 128
            || $plan['complete'] !== true || ! is_array($plan['paths']) || $plan['paths'] === [] || count($plan['paths']) > 256) {
            throw new InvalidArgumentException('Invalid complete analysis manifest.');
        }
        $source = StrictJson::fields($plan['source'], ['brief_id', 'revision_sha256']);
        if (! is_string($source['brief_id']) || trim($source['brief_id']) === '' || ! is_string($source['revision_sha256'])
            || preg_match(self::DigestPattern, $source['revision_sha256']) !== 1) {
            throw new InvalidArgumentException('Invalid manifest source provenance.');
        }
        $paths = [];
        $seen = [];
        $previous = null;
        foreach ($plan['paths'] as $entry) {
            if (! $entry instanceof \stdClass || ! is_string($entry->change ?? null)) {
                throw new InvalidArgumentException('Invalid typed path entry.');
            }
            $fields = StrictJson::fields($entry, $entry->change === 'rename' ? ['change', 'from', 'path'] : ['change', 'path']);
            if (! in_array($entry->change, ['add', 'modify', 'delete', 'rename'], true)) {
                throw new InvalidArgumentException('Unknown path change.');
            }
            self::path($fields['path']);
            $from = '';
            if ($entry->change === 'rename') {
                $from = $fields['from'];
                self::path($from);
            }
            foreach ($entry->change === 'rename' ? [$from, $fields['path']] : [$fields['path']] as $path) {
                if (isset($seen[$path])) {
                    throw new InvalidArgumentException('Duplicate or conflicting manifest path.');
                }
                $seen[$path] = true;
                $paths[] = $path;
            }
            $order = [$fields['path'], $entry->change, $from];
            if ($previous !== null && strcmp(implode("\0", $previous), implode("\0", $order)) >= 0) {
                throw new InvalidArgumentException('Manifest paths must be in UTF-8 byte order.');
            }
            $previous = $order;
        }
        $canonical = self::canonical($value);
        if (strlen($canonical) > self::ManifestBytes || ! hash_equals(hash('sha256', $canonical), $digest)) {
            throw new InvalidArgumentException('Manifest is oversized or its RFC 8785 digest differs.');
        }
        $manifest = StrictJson::associative($value);

        return new self($manifest, $paths, $canonical, $digest);
    }

    /** @phpstan-assert string $path */
    public static function path(mixed $path): void
    {
        if (! is_string($path) || $path === '' || strlen($path) > 512 || preg_match('/\A[\s\p{Z}]|[\s\p{Z}]\z/u', $path) === 1
            || preg_match('//u', $path) !== 1 || preg_match('/[\p{Cc}\\\\*?\[\]{}]/u', $path) === 1
            || preg_match('/\A[A-Za-z]:/D', $path) === 1 || str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Unsafe repository-relative file path.');
        }
        foreach (explode('/', $path) as $component) {
            if (in_array($component, ['', '.', '..', '.git'], true)) {
                throw new InvalidArgumentException('Unsafe path component.');
            }
        }
    }

    /** RFC 8785 for this closed schema: ASCII property names, safe integers, UTF-8 string values, no floats. */
    private static function canonical(mixed $value): string
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            $parts = [];
            foreach ($fields as $key => $item) {
                $parts[] = self::canonical($key).':'.self::canonical($item);
            }

            return '{'.implode(',', $parts).'}';
        }
        if (is_array($value)) {
            return '['.implode(',', array_map(self::canonical(...), $value)).']';
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
    }
}
