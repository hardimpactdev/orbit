<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Support\Collection;

/**
 * The digest of each whole file a Route write replaces on a Node, rendered from the sites as they are at admission: what the
 * engine's guarded steps require the Node to still hold (`route.withdraw_site`, `dns.publish_records` with
 * `dns.guarded_records`). A plan's publication states keep only the changed blocks, so they cannot give these digests.
 */
final readonly class RouteFileGuards
{
    public function __construct(private DevelopmentSiteRepository $sites, private NativeRoutePublicationInput $publications) {}

    /**
     * With `$after`, the sites the Nodes serve once the write is done, each Node also records the PHP versions its shared pools
     * keep (`php_versions_after`), so a withdrawal can tell a version that disappears from one it leaves untouched.
     *
     * @param  list<int>  $nodes
     * @param  Collection<int, DevelopmentSite>|null  $after
     * @return array<int, array<string, string>>
     */
    public function capture(array $nodes, ?Collection $after = null): array
    {
        $sites = $this->sites->all();
        $guards = [];
        foreach (array_values(array_unique($nodes)) as $id) {
            $node = Node::query()->find($id);
            if ($node === null) {
                continue;
            }
            $before = $this->publications->snapshot($node, $sites, true);
            $caddy = ValidatedData::object($before['caddy'])['content'] ?? null;
            $dns = is_array($before['dns']) ? $before['dns'] : null;
            $guards[$id] = array_filter(['caddy' => is_string($caddy) ? hash('sha256', $caddy) : null,
                // The shared pools' PHP versions now: a withdrawal must keep each, or its Operation runs on Swamp.
                'php_versions' => implode(',', array_keys((array) (ValidatedData::object($before['php'])['configurations'] ?? []))),
                ...self::pools($before),
                ...($after === null ? [] : ['php_versions_after' => implode(',', array_keys((array) (ValidatedData::object(
                    $this->publications->snapshot($node, $after, true)['php'])['configurations'] ?? [])))]),
                'records' => $dns === null ? null : hash('sha256', ValidatedData::string($dns['records'])),
                'catalog' => $dns === null ? null : self::catalogDigest(ValidatedData::string($dns['catalog'])),
                // The catalog's bytes as rendered: the engine's config.apply compares the live file byte for byte.
                'catalog_file' => $dns === null ? null : hash('sha256', ValidatedData::string($dns['catalog']))], is_string(...));
        }

        return $guards;
    }

    /**
     * What the engine writes on each Node to withdraw Routes that a capture rendered away (`renders`, the whole after-state of
     * each Node), beside the digests of the files it replaces: the Caddyfile, the shared PHP-FPM pools whose content changes, and
     * a VPN Node's records and catalog. A Node whose pool of a PHP version empties gets `pool_removed`, which the engine
     * refuses, because only a desired file is placed.
     *
     * @param  array<array-key, mixed>  $renders
     * @param  Collection<int, DevelopmentSite>  $sites  The sites the Nodes serve now, the withdrawn Routes' sites included.
     * @return array<int, array<string, mixed>>
     */
    public function withdrawal(array $renders, Collection $sites): array
    {
        $nodes = [];
        foreach ($renders as $id => $render) {
            $node = Node::query()->find((int) $id);
            if ($node === null || ! is_array($render)) {
                continue;
            }
            $before = $this->publications->snapshot($node, $sites, true);
            $row = [];
            $after = $render['caddy'] ?? null;
            $current = ValidatedData::object($before['caddy'])['content'] ?? null;
            if (is_string($after) && is_string($current)) {
                $row['caddy'] = ['from_sha256' => hash('sha256', $current), 'content' => $after];
            }
            $was = (array) (ValidatedData::object($before['php'])['configurations'] ?? []);
            $now = (array) (is_array($render['php'] ?? null) ? ($render['php']['configurations'] ?? []) : []);
            foreach ($was as $version => $text) {
                if (! array_key_exists($version, $now)) {
                    $row['pool_removed'] = true;
                } elseif ($now[$version] !== $text) {
                    $row['pools'][] = ['version' => (string) $version, 'content' => ValidatedData::string($now[$version]), 'from' => ValidatedData::string($text)];
                }
            }
            $dns = is_array($render['dns'] ?? null) ? $render['dns'] : null;
            $live = is_array($before['dns']) ? $before['dns'] : null;
            if ($dns !== null && $live !== null && ($dns['records'] !== $live['records'] || $dns['catalog'] !== $live['catalog'])) {
                $row['dns']['records'] = ['from_sha256' => hash('sha256', ValidatedData::string($live['records'])), 'content' => ValidatedData::string($dns['records'])];
                $row['dns']['catalog'] = ['from_sha256' => self::catalogDigest(ValidatedData::string($live['catalog'])), 'content' => ValidatedData::string($dns['catalog'])];
            }
            $nodes[(int) $id] = $row;
        }

        return $nodes;
    }

    /**
     * The guard digest of a private DNS catalog: its canonical form, the JSON with every object's keys in byte order, an empty
     * array read as an empty object (PHP's empty map), compact, and one newline. Swamp's withdrawal writes the same catalog
     * with its merge's key order and `{}` where this renderer writes `[]`; the executor's `catalog_sha256` (dns crate) hashes
     * the live file the same way, so only a change of content refuses.
     */
    public static function catalogDigest(string $catalog): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if ($value instanceof \stdClass) {
                $fields = get_object_vars($value);
                ksort($fields, SORT_STRING);

                return (object) array_map($canonical, $fields);
            }

            return is_array($value) ? ($value === [] ? new \stdClass : array_map($canonical, $value)) : $value;
        };

        return hash('sha256', json_encode($canonical(json_decode($catalog, false, 512, JSON_THROW_ON_ERROR)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * Each shared pool as rendered now, keyed `php:<version>`: the executor places the Node's group and home into it and
     * replaces the live pool only when it still holds that.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, string>
     */
    private static function pools(array $before): array
    {
        $pools = [];
        foreach ((array) (ValidatedData::object($before['php'])['configurations'] ?? []) as $version => $text) {
            $pools['php:'.$version] = ValidatedData::string($text);
        }

        return $pools;
    }

    /** Whether the engine may run `$kind`, so its admission records the digests. */
    public static function wanted(string $kind): bool
    {
        return in_array($kind, (array) config('orbit.engine.kinds'), true);
    }
}
