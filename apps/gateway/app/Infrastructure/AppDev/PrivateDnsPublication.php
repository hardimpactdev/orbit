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
use App\Support\ValidatedData;
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
                $selected = data_get($member->settings, ClusterDnsEvidence::Key.'.effective.delegate');
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
        return self::revisions($dns, $this->revisions->next());
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

        return self::content($a) == self::content($b);
    }

    /** @param array<string,mixed> $dns */
    public static function contentDigest(array $dns): string
    {
        return hash('sha256', json_encode(self::content($dns), JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private static function content(array $value): array
    {
        unset($value['content_digest']);

        return self::revisions($value, null);
    }

    /** @param array<string,mixed> $dns
     * @return array<string,mixed>
     */
    private static function revisions(array $dns, ?string $revision): array
    {
        if ($revision === null) {
            unset($dns['revision']);
        } else {
            $dns['revision'] = $revision;
        }
        foreach (['routers' => 'dns', 'retired' => null, 'members' => 'policy'] as $field => $nested) {
            if (! array_key_exists($field, $dns)) {
                continue;
            }
            $entries = $dns[$field];
            if (! is_array($entries)) {
                throw new \UnexpectedValueException('DNS publication targets must be an array.');
            }
            foreach ($entries as $index => $entry) {
                $entry = ValidatedData::object($entry);
                $projection = $nested === null ? $entry : ValidatedData::object($entry[$nested]);
                if ($revision === null) {
                    unset($projection['revision']);
                } else {
                    $projection['revision'] = $revision;
                }
                $entries[$index] = $nested === null ? $projection : [...$entry, $nested => $projection];
            }
            $dns[$field] = $entries;
        }

        return $dns;
    }
}
