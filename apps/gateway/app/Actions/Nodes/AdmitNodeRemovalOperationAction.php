<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeRemovalEnvironmentSource;
use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalIntent;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalPolicy;
use App\Domain\Nodes\NodeRoleMetricsInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Validate public intent and capture immutable participants. No reachability or Node execution. */
final readonly class AdmitNodeRemovalOperationAction
{
    public function __construct(private StartOperationAction $operations, private NodeRemovalPolicy $policy, private NodeRemovalPlan $plan) {}

    public function execute(Node $node, Node $caller, bool $offline = false, bool $force = false, bool $deleted = false, ?string $requestId = null): Operation
    {
        if ($deleted || TaskVm::query()->where('node_id', $node->id)->exists()) {
            throw new ResourceOperationException('node.removal_owner_required', 'Remove a TaskVM Node inside its owning native TaskVM workflow.', 422);
        }
        if ($offline && ! $force) {
            throw new ResourceOperationException('node.confirmation_required', 'Offline Node removal requires explicit force confirmation.', 422);
        }
        $this->policy->protected($node, $caller);
        $this->policy->resources($node, $offline);

        return DB::transaction(function () use ($node, $caller, $offline, $force, $requestId): Operation {
            $snapshot = $this->plan->build(Node::query()->lockForUpdate()->findOrFail($node->id), $offline);
            $executor = Node::query()->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
            $participants = [$caller->id, $node->id, $snapshot['hub_id'], ...array_map(static fn (mixed $participant): int => ValidatedData::integer(ValidatedData::object($participant)['node_id']), is_array($snapshot['participants']) ? $snapshot['participants'] : [])];
            if ($snapshot['metrics'] !== null) {
                $metrics = ValidatedData::object($snapshot['metrics']);
                $participants = [...$participants, $metrics['node_id'], $metrics['gateway_id']];
            }

            $participants[] = $executor->id;
            $execution = app(NodeRemovalInput::class)->capture($snapshot, executor: $executor);
            $snapshot = ValidatedData::object($execution['snapshot']);
            if ($execution['restore_certificates'] !== []) {
                $participants[] = 209;
            }
            $fleet = is_array($execution['metrics_fleet']) ? $execution['metrics_fleet'] : null;
            $metricsOwner = $fleet['metrics_node_id'] ?? $executor->id;
            $participants = [...$participants, ...NodeRoleMetricsInput::participants($fleet)];
            $participants = [...$participants, ...array_map(static fn (mixed $row): int => ValidatedData::integer(ValidatedData::object($row)['node_id']),
                is_array($snapshot['participants']) ? $snapshot['participants'] : [])];
            $input = ['offline' => $offline, 'force' => $force, '_node_removal' => $snapshot, '_removal' => $execution,
                'route_removal' => $execution['routes'],
                'route_prior_states' => Route::query()->whereKey(ValidatedData::integerList($snapshot['route_ids']))->orderBy('id')->get()
                    ->map(static fn (Route $route): array => ['id' => $route->id, ...$route->only(['status', 'sites_published', 'failed_step', 'error_code'])])->all(),
                'participant_node_ids' => array_values(array_unique(array_filter($participants, is_int(...))))];
            if ($execution['retirement'] !== null) {
                $input['bootstrap_identity'] = ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $executor->id];
                $input['private_bindings'] = ['bootstrap_identity' => ['owner_node_id' => $executor->id,
                    'read_node_ids' => [$executor->id], 'write_node_ids' => []]];
            }
            if ($execution['hub'] !== null) {
                $hub = ValidatedData::object($execution['hub']);
                $hubId = ValidatedData::integer($hub['hub_id']);
                $input['_wireguard'] = $hub;
                $input['_wireguard_restore'] = $execution['restore_hub'];
                $input['configuration'] = ['$secret' => 'node.vpn_configuration', 'id' => $hubId];
                $input['private_bindings']['configuration'] = ['owner_node_id' => $hubId,
                    'read_node_ids' => [$executor->id], 'write_node_ids' => []];
            }
            if ($fleet !== null) {
                $input['metrics_runtime'] = ['$secret' => 'metrics.fleet_runtime', 'id' => $metricsOwner];
                $input['private_bindings']['metrics_runtime'] = ['owner_node_id' => $metricsOwner, 'read_node_ids' => [$metricsOwner], 'write_node_ids' => []];
            }
            $input = NodeRemovalEnvironmentSource::bind($input, $executor->id);
            $input['execution_secrets'] = [];
            if (! $offline && $execution['retirement'] !== null) {
                $input['execution_secrets']['bootstrap_identity'] = app(SwampCommandStep::class)->executionCopy(
                    'orbit-control-inputs', 'bootstrap-identity-node-'.$executor->id,
                    File::get(app(SshKeyProvider::class)->privateKeyPath()));
            }
            $input['_removal'] = app(NodeRemovalIntent::class)->capture($node, $executor, $input);

            return $this->operations->execute('node.remove', 'node', $node->id, $executor->id, $input, 'operator', $requestId);
        });
    }
}
