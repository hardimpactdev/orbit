<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteTargetState;
use App\Actions\Routes\StartCertificateOperationAction;
use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Admitted target associations and local preparation inputs. Contains no execution. */
final readonly class NativeRouteTargetIntent
{
    public function __construct(private RouteTargetState $targets) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function forRoute(Route $route, array $input): array
    {
        $mode = ValidatedData::string($input['mode'] ?? 'targets');
        if (! in_array($mode, ['target', 'targets', 'targets_recover', 'state_only'], true)) {
            throw new ResourceOperationException('route.target_conflict', 'The target mutation mode is invalid.', 422);
        }
        if ($mode === 'targets_recover') {
            $previous = Operation::query()->findOrFail(ValidatedData::integer($input['recovery_operation_id']));
            $snapshot = ValidatedData::object($previous->input['route_target'] ?? []);
            if (($snapshot['route_id'] ?? null) !== $route->id) {
                throw new ResourceOperationException('route.target_set_conflict', 'The saved target-set intent belongs to another Route.', 409);
            }

            return [...$snapshot, 'mode' => $mode, 'recover_forward' => true];
        }
        $binding = $mode === 'target';
        $noop = $mode === 'state_only';
        $proposal = self::proposal($input);
        $prepared = [];
        if ($binding) {
            $instance = Instance::query()->with(['node', 'project'])->findOrFail(ValidatedData::integer($input['instance_id']));
            if (! $route->targets()->where('instance_id', $instance->id)->exists()) {
                throw new ResourceOperationException('route.target_conflict', 'The admitted Instance must be bound to the Route.', 409);
            }
            $pairs = [[$instance, $route]];
        } else {
            $pairs = $noop ? [] : $this->targets->preparedInstances($route, $proposal);
        }
        $staging = [];
        $live = [];
        $routers = [];
        foreach ($pairs as [$instance, $destination]) {
            $destination->loadMissing('cluster.routerAssignment.node');
            $prepared[] = ['route_id' => $destination->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                'production' => $instance->placedOnAppProd(), 'certificate_scope' => "app-instance-{$instance->id}-hostname-change"];
            if ($binding) {
                continue;
            }
            $certificates = app(StartCertificateOperationAction::class);
            $staging[] = $certificates->convergenceInput($instance->node, "app-instance-{$instance->id}-hostname-change", $destination->domain);
            $live[] = $certificates->convergenceInput($instance->node, "app-instance-{$instance->id}", $destination->domain);
            $router = $destination->cluster?->routerAssignment?->node;
            if ($router instanceof Node) {
                $routers[] = $router->id;
                if ($router->id !== $instance->node_id) {
                    $staging[] = $certificates->convergenceInput($router, "route-{$destination->id}-router", $destination->domain);
                }
            }
        }
        $vacated = $binding || $noop ? [] : $this->targets->vacatedRouteIds($route, $proposal);
        $removals = [];
        foreach ($proposal->dispositions as $disposition) {
            if ($disposition->routeId !== null) {
                $vacated[] = $disposition->routeId;
            }
            if ($disposition->remove) {
                $instance = Instance::query()->findOrFail($disposition->instanceId);
                $removals[] = ['instance_id' => $instance->id, 'node_id' => $instance->node_id,
                    'project_id' => $instance->project_id, 'checkout_path' => $instance->checkout_path];
            }
        }
        foreach (Route::query()->with('cluster.routerAssignment')->whereKey($vacated)->get() as $vacatedRoute) {
            if ($vacatedRoute->cluster?->routerAssignment?->node_id !== null) {
                $routers[] = $vacatedRoute->cluster->routerAssignment->node_id;
            }
        }
        $dns = $binding || $noop ? [] : PrivateDnsPublicationNodes::query()->orderBy('id')->pluck('id')->all();
        $workloads = array_values(array_unique(array_column($prepared, 'node_id')));
        $routers = array_values(array_unique($routers));
        $route->loadMissing(['targets.instance', 'cluster.routerAssignment']);
        $owners = $route->targets->map(static fn ($target): int => $target->instance->node_id)->all();
        $roots = array_values(array_filter([$route->node_id, $route->cluster?->routerAssignment?->node_id], is_int(...)));
        $nodes = array_values(array_unique([...$workloads, ...$routers, ...$dns, ...$owners, ...$roots, ...array_column($removals, 'node_id')]));
        sort($nodes);
        $unique = [];
        foreach ($staging as $leaf) {
            $unique[$leaf['node_id'].':'.$leaf['scope'].':'.$leaf['domain']] ??= $leaf;
        }
        $staging = array_values($unique);

        return ['route_id' => $route->id, 'mode' => $mode, 'binding_only' => $binding, 'noop' => $noop,
            'recover_forward' => $mode === 'targets_recover', 'prepared' => $prepared, 'workloads' => $prepared,
            'proposal' => $proposal->toIntent(), 'vacated_route_ids' => array_values(array_unique($vacated)), 'removals' => $removals,
            'staging_certificates' => $staging, 'live_certificates' => $live, 'certificates' => [...$staging, ...$live],
            'staging_removals' => array_map(static fn (array $leaf): array => array_intersect_key($leaf, array_flip(['node_id', 'scope', 'home', 'agent_sha256'])) + ['in_use' => false],
                array_values(array_filter($staging, static fn (array $leaf): bool => str_ends_with($leaf['scope'], '-hostname-change')))),
            'node_ids' => $nodes, 'workload_node_ids' => $workloads, 'router_node_ids' => $routers, 'dns_node_ids' => $dns,
            'caddy_node_ids' => array_values(array_unique([...$workloads, ...$routers]))];
    }

    /** @param array<string,mixed> $input */
    public static function proposal(array $input): SetRouteTargetsData
    {
        return new SetRouteTargetsData(ValidatedData::integerList($input['targets'] ?? []), array_map(
            static fn (array $value): RouteTargetDispositionData => new RouteTargetDispositionData(ValidatedData::integer($value['instance_id']),
                isset($value['route_id']) ? ValidatedData::integer($value['route_id']) : null, ($value['remove'] ?? false) === true),
            array_map(ValidatedData::object(...), $input['dispositions'] ?? [])));
    }

    /** @return list<int> */
    public static function participants(Operation $operation): array
    {
        return ValidatedData::integerList(ValidatedData::object($operation->input['route_target'] ?? [])['node_ids'] ?? []);
    }

    /** Fresh public artifacts are rendered inside the native publication lock.
     * @return array<string,mixed>
     */
    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $intent = ValidatedData::object($operation->input['route_target'] ?? []);
        if (! in_array($callerNodeId, self::participants($operation), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is not an admitted target-set participant.', 403);
        }
        $node = Node::query()->findOrFail($callerNodeId);
        $pin = app(AgentDelivery::class)->pin($node);
        $workloads = [];
        $probes = [];
        foreach (array_map(ValidatedData::object(...), $intent['prepared']) as $member) {
            $instance = Instance::query()->with(['node', 'project'])->findOrFail(ValidatedData::integer($member['instance_id']));
            $route = Route::query()->with('cluster.routerAssignment.node')->findOrFail(ValidatedData::integer($member['route_id']));
            $router = $route->cluster?->routerAssignment?->node;
            if ($member['node_id'] === $callerNodeId) {
                if ($member['production']) {
                    [$runtime] = app(InstanceDeploymentIntent::class)->runtimeIntent($instance, $route);
                    $extra = ['runtime' => $runtime, 'profile' => $runtime === null ? null : app(RemotePhpPackageManager::class)->runtimeInput($node, $instance->selected_php_version),
                        'packages' => $runtime === null ? null : app(RemotePhpPackageManager::class)->packagesOnlyInputForAppProd($node, [$instance->selected_php_version])];
                } else {
                    $extra = app(RouteWorkloadInput::class)->forInstance($instance, $route, $callerNodeId);
                }
                $workloads[] = [...$member, ...$extra, 'lan' => $router instanceof Node ? app(RouteHopInput::class)->firewall($instance, $route, $router, $callerNodeId) : null];
            }
            if ($router?->id === $callerNodeId && $instance->node_id !== $callerNodeId) {
                $probes[] = ['protocol' => 'tls', 'agent_sha256' => $pin,
                    'probe' => ['address' => $instance->node->wireguard_ip, 'port' => 443, 'domain' => $route->domain]];
            }
        }
        $artifacts = app(NativeRoutePublicationInput::class)->forNode($node);

        return [...$artifacts, 'target' => $intent, 'environment_fields' => $operation->input['environment_fields'] ?? [],
            'publication' => ['node_id' => $callerNodeId, 'agent_sha256' => $pin, 'caddy_publication' => $artifacts['caddy_publication'],
                'php' => $artifacts['php_publication'], 'dns' => $artifacts['private_dns_publication']],
            'workloads' => $workloads, 'probes' => $probes];
    }
}
