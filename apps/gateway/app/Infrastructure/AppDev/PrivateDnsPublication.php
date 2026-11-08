<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\ClusterTldTargets;
use App\Domain\Clusters\ClusterDnsEvidence;
use App\Domain\Clusters\ClusterDnsTransition;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\RouteRenderContext;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\DB;

/**
 * One private DNS publication: the complete state as the phase on the vpn Node takes it, with its revision.
 * The revision is taken under the revision lock in the transaction that reads the rows. A change stores its
 * rows before it asks for a render, so a later revision holds every change stored before it.
 */
final readonly class PrivateDnsPublication
{
    public function __construct(
        private DevelopmentDnsConfigRenderer $renderer,
        private PrivateDnsRevision $revisions,
    ) {}

    /** @return array{revision: string, listen: list<string>, tlds: list<array{name: string, address: string}>, records: list<array{name: string, address: string, pair: bool}>} */
    public function render(): array
    {
        return DB::transaction(function (): array {
            $revision = $this->revisions->next();
            $state = ['revision' => $revision, ...$this->renderer->state()->toIntent()];
            $routers = [];
            $retired = [];
            foreach (Cluster::query()->orderBy('id')->get() as $cluster) {
                $pending = $cluster->pendingDns();
                $router = ($pending['state'] ?? $cluster->state->value) === 'active'
                    ? app(ClusterTldTargets::class)->router($cluster, $pending) : null;
                $selectedRouterId = null;
                if ($router !== null && app(RouteRenderContext::class)->lanIp($router) !== null) {
                    $selectedRouterId = $router->id;
                    $routers[] = ['node_id' => $router->id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($router),
                        'dns' => ['revision' => $revision, 'kind' => 'cluster', 'cluster_id' => $cluster->id,
                            'upstream' => $state['listen'], ...$this->renderer->routerState($cluster, $router)->toIntent()]];
                }
                foreach (NodeRole::query()->with('node')->where('cluster_id', $cluster->id)->where('role', 'router')->get() as $assignment) {
                    if ($assignment->node_id !== $selectedRouterId && (in_array($assignment->status->value, ['active', 'removing'], true) || ClusterRouterTransition::candidateServes($assignment))) {
                        $retired[] = ['node_id' => $assignment->node_id, 'cluster_id' => $cluster->id, 'revision' => $revision,
                            ...app(NativeCaddyPublicationInput::class)->connectionForNode($assignment->node)];
                    }
                }
            }

            $state['routers'] = $routers;
            $state['retired'] = $retired;
            $members = [];
            $policy = app(PeerDnsPolicy::class);
            foreach (Node::query()->with(['roles', 'cluster'])->orderBy('id')->get() as $member) {
                $pending = isset($member->settings[ClusterDnsTransition::Key]);
                $selected = $member->settings[ClusterDnsEvidence::Key]['effective']['delegate'] ?? null;
                if (($pending || is_array($selected) || (app(RouteRenderContext::class)->clusterId($member) !== null && app(RouteRenderContext::class)->lanIp($member) !== null)) && $policy->manages($member)) {
                    $members[] = ['node_id' => $member->id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($member),
                        'policy' => [...$policy->selection($member), 'revision' => $revision]];
                }
            }
            $state['members'] = $members;

            if ($routers === [] && $retired === [] && $members === []) {
                unset($state['routers'], $state['retired'], $state['members']);
            }

            return $state;
        });
    }

    /**
     * What a parent workflow or the dns.publish operation carries for one vpn Node.
     *
     * @return array<string, mixed>
     */
    public function entry(Node $node, bool $clusterOnly = false): array
    {
        $dns = $this->render();
        if ($clusterOnly) {
            $dns['cluster_only'] = true;
        }

        return ['node_id' => $node->id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($node), 'dns' => $dns];
    }

    /**
     * The same render with a newer revision. A restore that runs after its forward publication takes one, so the Node installs it.
     *
     * @param  array<string, mixed>  $dns
     * @return array<string, mixed>
     */
    public function restamp(array $dns): array
    {
        $revision = $this->revisions->next();
        $dns['revision'] = $revision;
        foreach ($dns['routers'] ?? [] as $index => $router) {
            $dns['routers'][$index]['dns']['revision'] = $revision;
        }
        foreach ($dns['retired'] ?? [] as $index => $retired) {
            $dns['retired'][$index]['revision'] = $revision;
        }

        foreach ($dns['members'] ?? [] as $index => $member) {
            $dns['members'][$index]['policy']['revision'] = $revision;
        }

        return $dns;
    }

    /**
     * Whether two renders publish the same DNS. The revision does not count.
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    public static function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        $strip = static function (array $value): array {
            unset($value['revision']);
            foreach ($value['routers'] ?? [] as $index => $router) {
                unset($value['routers'][$index]['dns']['revision']);
            }
            foreach ($value['retired'] ?? [] as $index => $retired) {
                unset($value['retired'][$index]['revision']);
            }

            foreach ($value['members'] ?? [] as $index => $member) {
                unset($value['members'][$index]['policy']['revision']);
            }

            return $value;
        };
        $a = $strip($a);
        $b = $strip($b);

        return $a == $b;
    }
}
