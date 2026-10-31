<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\NativeRouteCertificateInput;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\RouteExecutionProof;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Support\Collection;

/** Admission-only public changes; canonical Route workflows perform the effects. */
final readonly class AnalyticsDisableInput
{
    public function __construct(private NativeRoutePublicationInput $publications, private NativeCaddyPublicationInput $connections,
        private DevelopmentSiteRepository $sites) {}

    /** @param array<string,mixed> $tracking
     * @param  Collection<int,DevelopmentSite>  $beforeSites
     * @param  array<string,mixed>|null  $site
     * @return array<string,mixed>
     */
    public function capture(array $tracking, Collection $beforeSites, ?array $site): array
    {
        $afterSites = $this->sites->all();
        $caddy = [];
        $dns = [];
        $cleanup = [];
        foreach (array_values(array_unique([...ValidatedData::integerList($tracking['caddy_node_ids']),
            ...ValidatedData::integerList($tracking['dns_node_ids'])])) as $nodeId) {
            $node = Node::query()->findOrFail($nodeId);
            $connection = $this->connections->connectionForNode($node);
            $before = $this->publications->snapshot($node, $beforeSites);
            $after = $this->publications->snapshot($node, $afterSites);
            if (in_array($nodeId, ValidatedData::integerList($tracking['caddy_node_ids']), true)) {
                $caddy[] = ['node_id' => $nodeId, 'user' => $node->user, ...$connection,
                    'before' => $before['caddy'], 'after' => $after['caddy'], 'direction' => 'forward'];
            }
            if (in_array($nodeId, ValidatedData::integerList($tracking['dns_node_ids']), true)) {
                $dns[] = ['node_id' => $nodeId, ...$connection, 'before' => $before['dns'], 'after' => $after['dns'], 'alternatives' => []];
            }
            foreach (RouteExecutionProof::objects($tracking['cleanup']) as $raw) {
                $leaf = AnalyticsTrackingIntent::decodeLeaf($raw);
                if ($leaf['node_id'] === $nodeId && ! in_array($leaf['scope'], ValidatedData::stringList($after['certificate_scopes']), true)) {
                    $cleanup[] = ['leaf' => [...app(NativeRouteCertificateInput::class)->scopeInput($node, $leaf['scope']), 'in_use' => false], ...$connection];
                }
            }
        }

        return ['caddy' => $caddy, 'dns' => $dns, 'cleanup' => $cleanup,
            'result_json' => json_encode(['ready' => true, 'enabled' => false, 'detached' => true,
                'domain' => $site['domain'] ?? null, 'timezone' => $site['timezone'] ?? null], JSON_THROW_ON_ERROR)];
    }
}
