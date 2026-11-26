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
     * @param  list<int>  $nodes
     * @return array<int, array<string, string>>
     */
    public function capture(array $nodes): array
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
                'records' => $dns === null ? null : hash('sha256', ValidatedData::string($dns['records'])),
                'catalog' => $dns === null ? null : hash('sha256', ValidatedData::string($dns['catalog']))], is_string(...));
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
                    $row['pools'][] = ['version' => (string) $version, 'content' => ValidatedData::string($now[$version])];
                }
            }
            $dns = is_array($render['dns'] ?? null) ? $render['dns'] : null;
            $live = is_array($before['dns']) ? $before['dns'] : null;
            if ($dns !== null && $live !== null && ($dns['records'] !== $live['records'] || $dns['catalog'] !== $live['catalog'])) {
                foreach (['records', 'catalog'] as $file) {
                    $row['dns'][$file] = ['from_sha256' => hash('sha256', ValidatedData::string($live[$file])), 'content' => ValidatedData::string($dns[$file])];
                }
            }
            $nodes[(int) $id] = $row;
        }

        return $nodes;
    }

    /** Whether the engine may run `$kind`, so its admission records the digests. */
    public static function wanted(string $kind): bool
    {
        return in_array($kind, (array) config('orbit.engine.kinds'), true);
    }
}
