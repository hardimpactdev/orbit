<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

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
    public function __construct(private NativeRoutePublicationInput $publications,
        private DevelopmentSiteRepository $sites) {}

    /** @param array<string,mixed> $tracking
     * @param  Collection<int,DevelopmentSite>  $beforeSites
     * @param  array<string,mixed>|null  $site
     * @return array<string,mixed>
     */
    public function capture(array $tracking, Collection $beforeSites, ?array $site): array
    {
        $afterSites = $this->sites->all();
        $caddy = $dns = $cleanup = [];
        foreach (array_values(array_unique([...ValidatedData::integerList($tracking['caddy_node_ids']),
            ...ValidatedData::integerList($tracking['dns_node_ids'])])) as $nodeId) {
            $node = Node::query()->findOrFail($nodeId);
            $before = $this->publications->snapshot($node, $beforeSites);
            $after = $this->publications->snapshot($node, $afterSites);
            $content = ValidatedData::object($after['caddy'])['content'] ?? null;
            if (in_array($nodeId, ValidatedData::integerList($tracking['caddy_node_ids']), true) && is_string($content)
                && $content !== (ValidatedData::object($before['caddy'])['content'] ?? null)) {
                $caddy[] = ['node_id' => $nodeId, 'content' => $content];
            }
            if (in_array($nodeId, ValidatedData::integerList($tracking['dns_node_ids']), true)) {
                $dns[] = ['node_id' => $nodeId, 'before' => $before['dns'], 'after' => $after['dns']];
            }
            foreach (RouteExecutionProof::objects($tracking['cleanup']) as $raw) {
                $leaf = AnalyticsTrackingIntent::decodeLeaf($raw);
                if ($leaf['node_id'] === $nodeId && ! in_array($leaf['scope'], ValidatedData::stringList($after['certificate_scopes']), true)) {
                    $cleanup[] = ['node_id' => $nodeId, 'leaf' => array_intersect_key(app(NativeRouteCertificateInput::class)->scopeInput($node, $leaf['scope']), array_flip(['user', 'scope']))];
                }
            }
        }

        return ['site' => $site, ...app(AnalyticsTrackingSteps::class)->build($caddy, $dns, $cleanup, [], [], false)];
    }
}
