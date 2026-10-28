<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Fleet\FleetNodeVerifier;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeCliFootprint;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class AdmitFleetRolloutNodeAction
{
    public function __construct(private FleetRolloutPlanAction $plan, private DesiredFleetState $desired, private FleetNodeVerification $doctor,
        private GatewayServingHost $gateway, private StartOperationAction $start, private AgentDelivery $delivery, private NodeUpdateBroadcaster $updates, private NodeReachabilityProbe $probe) {}

    public function execute(Node $node, int $rolloutId): Operation
    {
        $plan = $this->plan->execute();
        $target = array_find($plan['targets'] ?? [], static fn (array $row): bool => $row['node_id'] === $node->id);
        if (($plan['rollout'] ?? null) !== $rolloutId || $target === null || ! $target['window']) {
            throw new ResourceOperationException('fleet.rollout_not_ready', 'The rollout, target or maintenance window changed.', 409);
        }
        if (FleetRolloutNode::query()->where('fleet_rollout_id', $rolloutId)->whereNotNull('started_at')->whereNull('finished_at')->exists()) {
            throw new ResourceOperationException('fleet.rollout_busy', 'A fleet Node visit is still running.', 409);
        }
        $state = $this->desired->current();
        $reachable = $this->probe->degradation($node) !== ExporterDegradationReason::Unreachable;
        $baseline = $reachable ? $this->doctor->baseline($node) : [];
        $release = null;
        foreach ($state->cli->assets as $asset) {
            if ($asset->platform === NodeCliFootprint::platform((string) $node->architecture)) {
                $release = ['version' => $state->cli->version, 'url' => $asset->url, 'sha256' => $asset->sha256];
            }
        }
        $gateway = Node::query()->findOrFail($this->gateway->nodeId());
        if ($release === null || ! is_string($gateway->wireguard_ip)) {
            throw new ResourceOperationException('cli.release_unavailable', 'The desired CLI release or Gateway address is unavailable.', 409);
        }

        return DB::transaction(function () use ($node, $plan, $target, $state, $baseline, $release, $gateway, $rolloutId, $reachable): Operation {
            FleetRollout::query()->whereKey($rolloutId)->lockForUpdate()->firstOrFail();
            if (FleetRolloutNode::query()->where('fleet_rollout_id', $rolloutId)->whereNotNull('started_at')->whereNull('finished_at')->exists()) {
                throw new ResourceOperationException('fleet.rollout_busy', 'A fleet Node visit is still running.', 409);
            }
            $first = ! FleetRolloutNode::query()->where('fleet_rollout_id', $rolloutId)->whereIn('outcome', [FleetNodeOutcome::Converged, FleetNodeOutcome::Unchanged])->exists();
            $operation = $this->start->execute('fleet.rollout', 'node', $node->id, $node->id, [
                'node_id' => $node->id, 'reachable' => $reachable, 'rollout_id' => $rolloutId, 'row_id' => $target['row_id'], 'commit' => $state->commit,
                'user' => $node->user, 'release' => $release, 'profile' => NodeCliFootprint::configuration($gateway->wireguard_ip),
                'downgrade_to' => ($plan['allow_downgrade'] ?? false) ? $state->cli->version : null, 'first_visit' => $first,
                'daytime' => $node->roles->isNotEmpty() && $node->roles->every(static fn ($role): bool => $role->role->value === 'app-dev'),
                'baseline' => $baseline, 'repairs' => FleetNodeVerifier::repairs($baseline),
                'agent_version' => $this->delivery->reportedVersion($node),
            ], 'swamp', null);
            if ($operation->wasRecentlyCreated) {
                $row = FleetRolloutNode::query()->findOrFail($target['row_id']);
                $row->forceFill(['started_at' => now(), 'finished_at' => null,
                    'attempts' => $target['attempts'] + 1, 'evidence' => [...($row->evidence ?? []), 'operation_id' => $operation->id]])->save();
                DB::afterCommit(fn () => $this->updates->node($node->id));
            }

            return $operation;
        });
    }
}
