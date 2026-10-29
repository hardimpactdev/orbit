<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\FleetRolloutNode;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class RolloutOperationCompletion implements OperationCompletion
{
    public function __construct(private FleetRolloutAlerts $alerts, private NodeFootprint $footprint, private NodeUpdateBroadcaster $updates, private FleetFootprintChild $children) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $row = FleetRolloutNode::query()->findOrFail(ValidatedData::integer($operation->input['row_id']));
        $previous = $row->evidence ?? [];
        $code = $outcome->errorCode;
        $result = $outcome->result ?? [];
        if ($outcome->status === OperationStatus::Succeeded) {
            $this->children->succeeded($operation);
            if (! $this->children->cliReady($operation)) {
                throw new ResourceOperationException('operation.completion_unproven', 'The CLI installation and update need proven receipts.', 409);
            }
        }
        $status = $outcome->status === OperationStatus::Succeeded ? (($result['changed'] ?? false) ? FleetNodeOutcome::Converged : FleetNodeOutcome::Unchanged) : match ($code) {
            'cli.foreign_binary' => FleetNodeOutcome::Skipped,
            'fleet.window_closed' => FleetNodeOutcome::Deferred,
            'node.ssh_unreachable' => FleetNodeOutcome::Unreachable,
            'fleet.release_pending', 'fleet.self_update_incomplete' => FleetNodeOutcome::Waiting,
            'node_role.node_busy', 'node.update_busy', 'self_update.busy', 'agent.converge_busy', 'agent.converge_lock_lost', 'node.lock_lost', 'tool.operation_locked' => FleetNodeOutcome::Deferred,
            default => FleetNodeOutcome::Failed,
        };
        $evidence = ['operation_id' => $operation->id, 'steps' => $operation->state['steps'] ?? []];
        if (! $status->isConverged()) {
            foreach (['incomplete_visits', 'stalled_alert'] as $key) {
                if (isset($previous[$key])) {
                    $evidence[$key] = $previous[$key];
                }
            }
        }
        $row->forceFill(['outcome' => $status, 'step' => $operation->steps()->where('status', 'failed')->value('name'), 'error_code' => $code, 'evidence' => $evidence,
            'cli_version' => $result['cli_version'] ?? $row->cli_version, 'agent_version' => $result['agent_version'] ?? $row->agent_version,
            'footprint_digest' => $row->node === null ? null : $this->footprint->recorded($row->node)?->digest, 'finished_at' => now()])->save();
        DB::afterCommit(fn () => $this->updates->node($row->node_id));
        if ($outcome->status === OperationStatus::Succeeded && ($result['caddy_skipped'] ?? false) === true && ! isset($row->rollout->notices['caddy_skipped'])) {
            $skip = $this->children->caddySkip($this->children->succeeded($operation)->result ?? []);
            if ($skip !== null) {
                $row->rollout->forceFill(['notices' => [...($row->rollout->notices ?? []), 'caddy_skipped' => $this->alerts->caddySkipped($row->rollout, $row, ['message' => $skip['reason']])]])->save();
            }
        }
        if ($code === 'cli.foreign_binary' && $row->node !== null) {
            new NodeCliState()->markForeign($row->node);
        }
        if ($code === 'fleet.self_update_incomplete') {
            $visits = ValidatedData::integer($previous['incomplete_visits'] ?? 0) + 1;
            $evidence['incomplete_visits'] = $visits;
            if (! isset($evidence['stalled_alert']) && $visits >= FleetRolloutAlerts::IncompleteVisits) {
                $evidence['stalled_alert'] = $this->alerts->stalled($row->rollout, $row, $visits);
            }
            $row->forceFill(['evidence' => $evidence])->save();
        }
        if ($status === FleetNodeOutcome::Failed) {
            $row->rollout->forceFill(['status' => FleetRolloutStatus::Halted, 'halted_node_id' => $row->node_id, 'error_code' => $code,
                'message' => 'Fleet Node visit failed. Inspect Operation '.$operation->id.'.'])->save();
            $this->alerts->halted($row->rollout, $row);
        }
    }
}
