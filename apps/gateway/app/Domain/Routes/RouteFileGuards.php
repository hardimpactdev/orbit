<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Support\ValidatedData;

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

    /** Whether the engine may run `$kind`, so its admission records the digests. */
    public static function wanted(string $kind): bool
    {
        return in_array($kind, (array) config('orbit.engine.kinds'), true);
    }
}
