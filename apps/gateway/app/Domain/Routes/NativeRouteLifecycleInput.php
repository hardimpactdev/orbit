<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Routes\PublicProcessRouteFirewall;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;
use Closure;

/** Admission-only actual and prospective catalogs for standalone creation and withdrawal. */
final readonly class NativeRouteLifecycleInput
{
    public function __construct(private NativeRouteMutationInput $mutations, private DevelopmentSiteRepository $sites) {}

    /** @param array<string,mixed> $input
     * @return array{intent:array<string,mixed>,certificates:list<array<string,mixed>>,participant_node_ids:list<int>,execution_secrets:array<string,array{vault:string,key:string}>}
     */
    public function capture(Route $route, array $input, bool $removing = false, ?int $deletedWorkloadNodeId = null, bool $restorable = true): array
    {
        $route->loadMissing(['targets.instance.node', 'targets.instance.project', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node']);
        $metadata = ['route_id' => $route->id, 'result_route_id' => $route->id, 'effectful' => $removing || ($input['mode'] ?? null) !== 'state_only',
            'workloads' => [], 'offline' => ($input['offline'] ?? false) === true, 'removal' => $removing,
            'public_activated' => ! $removing && new PublicRouteEligibility()->canStartActivation($route)];
        if (! $metadata['effectful']) {
            return ['intent' => $metadata, 'certificates' => [], 'participant_node_ids' => [209], 'execution_secrets' => []];
        }
        if ($deletedWorkloadNodeId !== null && ! $removing) {
            throw new \LogicException('Only workspace removal may exclude its deleted workload Node.');
        }
        $beforeSites = $this->sites->all()->reject(static fn (DevelopmentSite $site): bool => $site->nodeId === $deletedWorkloadNodeId)->values();
        $inventory = $removing ? app(NativeRouteRemovalIntent::class)->captureMany(collect([$route]), excludedEffectNodeIds: $deletedWorkloadNodeId === null ? [] : [$deletedWorkloadNodeId]) : null;
        $prior = $deletedWorkloadNodeId === null ? $this->mutations->route($route, environment: false)
            : ['route_id' => $route->id, 'domain' => $route->domain, 'intent' => ['node_ids' => array_column($inventory['nodes'] ?? [], 'node_id'), 'certificates' => []], 'sites' => [], 'url_writes' => []];
        $prior['intent']['node_ids'] = array_values(array_filter(ValidatedData::integerList($prior['intent']['node_ids']), static fn (int $id): bool => $id !== $deletedWorkloadNodeId));
        $prior['sites'] = array_values($beforeSites->where('domain', $route->domain)->all());
        $prior['intent']['certificates'] = array_values(array_filter(RouteExecutionProof::objects($prior['intent']['certificates']),
            static fn (array $leaf): bool => array_any($prior['sites'], static fn (DevelopmentSite $site): bool => $site->nodeId === $leaf['node_id'] && ($site->certificateScope ?? $site->scope) === $leaf['scope'])));
        $prior['url_writes'] = [];
        foreach ($route->targets as $target) {
            if ($target->instance->node_id === $deletedWorkloadNodeId) {
                continue;
            }
            foreach (app(NativeRouteConvergenceEnvironmentMaterial::class)->forRoute($route, $target->instance->node_id,
                ['workloads' => [['instance_id' => $target->instance_id, 'node_id' => $target->instance->node_id, 'production' => $target->instance->placedOnAppProd()]]], false, urlsOnly: $removing) as $write) {
                $prior['url_writes'][] = ['node_id' => $target->instance->node_id, ...$write];
            }
        }
        $candidate = clone $route;
        $candidate->forceFill(['status' => $removing ? RouteStatus::Retiring : RouteStatus::Active, 'sites_published' => ! $removing,
            'replacement_step' => $metadata['public_activated'] ? RouteReplacementStep::Cleanup : null]);
        $desired = $removing ? [] : [$this->mutations->route($candidate, [$route->id => $candidate])];
        if ($removing) {
            // The empty desired Route still carries URL selection from the remaining stored Routes.
            $row = $prior;
            $row['sites'] = [];
            $row['intent']['certificates'] = [];
            $row['url_writes'] = [];
            foreach ($route->targets as $target) {
                if ($target->instance->node_id === $deletedWorkloadNodeId) {
                    continue;
                }
                foreach (app(NativeRouteConvergenceEnvironmentMaterial::class)->forRoute($candidate, $target->instance->node_id,
                    ['workloads' => [['instance_id' => $target->instance_id, 'node_id' => $target->instance->node_id, 'production' => $target->instance->placedOnAppProd()]]], true, [$route->id => $candidate], urlsOnly: true) as $write) {
                    $row['url_writes'][] = ['node_id' => $target->instance->node_id, ...$write];
                }
            }
            $desired = [$row];
        }
        $extra = $inventory === null ? [] : ValidatedData::integerList(array_column($inventory['nodes'], 'node_id'));
        $capture = $this->mutations->capture([$prior], $desired, $desired, [$prior], $beforeSites, $beforeSites,
            array_values(array_unique([...ValidatedData::integerList($prior['intent']['node_ids']), ...$extra])), static fn (Closure $render): array => $render(), restorable: $restorable);
        if ($inventory !== null) {
            $mutation = ValidatedData::object($capture['route_mutations']);
            $forward = ValidatedData::object($mutation['forward']);
            $cleanup = RouteExecutionProof::objects($forward['cleanup']);
            foreach ($inventory['nodes'] as $member) {
                foreach ($member['certificate_scopes'] as $scope) {
                    $cleanup[] = ['node_id' => $member['node_id'], 'user' => $member['user'], 'agent_sha256' => $member['agent_sha256'], 'scope' => $scope, 'in_use' => false];
                }
            }
            $forward['cleanup'] = array_values(array_unique($cleanup, SORT_REGULAR));
            $mutation['forward'] = $forward;
            $capture['route_mutations'] = $mutation;
        }
        $instances = [];
        foreach ($route->targets as $target) {
            if ($target->instance->node_id === $deletedWorkloadNodeId) {
                continue;
            }
            $instances[$target->instance_id] = $target->instance;
            $metadata['workloads'][] = app(NativeRouteWorkloadPreparation::class)->capture($target->instance, $route, $candidate, $removing);
        }
        if (! $removing) {
            $prepared = app(NativeRoutePreparationInput::class)->capture($capture, $desired, $desired, $beforeSites, $instances);
            $capture = $prepared['capture'];
            $metadata['preparation'] = $prepared['preparation'];
            $metadata['preparation']['upstream_firewalls'] = [];
            $metadata['verifications'] = [];
            if ($route->isCustomProxy() && $metadata['public_activated']) {
                $ingress = $route->cluster?->ingressAssignment?->node;
                if ($ingress === null) {
                    throw new \LogicException('Public Process capture requires the admitted Ingress.');
                }
                $expected = app(PublicProcessRouteFirewall::class)->expectation($candidate, $ingress);
                $process = $route->customProxy?->process;
                if ($process === null) {
                    throw new \LogicException('Public Process capture requires its recorded Process.');
                }
                $upstream = new CustomProxyProcessListener()->resolvePublic($process, $ingress);
                $probes = RouteExecutionProof::objects($metadata['preparation']['probes']);
                $probes[] = ['node_id' => $ingress->id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($ingress), 'protocol' => 'http', 'probe' => ['address' => $upstream->host, 'port' => $upstream->port, 'domain' => $route->domain]];
                $metadata['preparation']['probes'] = $probes;
                $metadata['preparation']['upstream_firewalls'][] = ['node_id' => $expected['node_id'], ...app(NativeCaddyPublicationInput::class)->connectionForNode(Node::query()->findOrFail($expected['node_id'])), 'expected' => $expected['expected']];
            }
            if ($metadata['public_activated']) {
                $ingress = $route->cluster?->ingressAssignment?->node;
                if ($ingress === null) {
                    throw new \LogicException('Public publication requires its Ingress.');
                }
                $metadata['verifications'][] = ['node_id' => $ingress->id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($ingress), 'probe' => ['address' => $ingress->wireguard_ip, 'port' => 443, 'domain' => $route->domain]];
            }
        }

        return ['intent' => [...$metadata, 'route_mutations' => $capture['route_mutations']],
            'certificates' => $capture['certificates'], 'participant_node_ids' => $capture['participant_node_ids'], 'execution_secrets' => $capture['execution_secrets']];
    }
}
