<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\FleetRolloutNode;
use App\Models\Operation;

/** One fleet visit at a time, including visits from a superseded rollout generation. */
final readonly class FleetRolloutVisits
{
    public const string AdmissionLock = 'fleet-rollout-admission';

    /** A long child wait must be visible without abandoning its target hold. */
    public const int StalledMinutes = 30;

    public function __construct(private RolloutOperationCompletion $completion, private FleetRolloutAlerts $alerts) {}

    /** Called only while the fleet admission lock is held. */
    public function reconcile(): void
    {
        foreach (FleetRolloutNode::query()->whereNotNull('started_at')->whereNull('finished_at')->get() as $row) {
            $id = $row->evidence['operation_id'] ?? null;
            $operation = is_int($id) ? Operation::query()->find($id) : null;
            if ($operation !== null && ($operation->kind !== 'fleet.rollout' || $operation->node_id !== $row->node_id
                || ($operation->input['row_id'] ?? null) !== $row->id)) {
                $operation = null;
            }
            if ($operation === null) {
                $row->forceFill(['outcome' => FleetNodeOutcome::Failed, 'error_code' => 'fleet.visit_interrupted',
                    'message' => 'The visit has no owning Operation. No automatic retry.', 'finished_at' => now()])->save();
                $row->rollout->forceFill(['status' => FleetRolloutStatus::Halted, 'halted_node_id' => $row->node_id,
                    'error_code' => 'fleet.visit_interrupted', 'message' => $row->message])->save();
                $this->alerts->halted($row->rollout, $row);
            } elseif ($operation->status->terminal()) {
                $outcome = match ($operation->status) {
                    OperationStatus::Succeeded => OperationOutcome::succeeded($operation->result ?? []),
                    OperationStatus::Cancelled => OperationOutcome::cancelled($operation->result),
                    default => OperationOutcome::failed($operation->error_code ?? 'fleet.visit_interrupted', $operation->result),
                };
                $this->completion->complete($operation, $outcome);
            } elseif ($row->started_at?->lte(now()->subMinutes(self::StalledMinutes)) && ! isset($row->evidence['open_alert'])) {
                $row->forceFill(['evidence' => [...($row->evidence ?? []), 'open_alert' => $this->alerts->openVisit($row->rollout, $row, $operation)]])->save();
            }
        }
    }

    public function busy(): bool
    {
        return FleetRolloutNode::query()->whereNotNull('started_at')->whereNull('finished_at')->exists()
            || Operation::query()->where('kind', 'fleet.rollout')->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])->exists();
    }

    public function assertIdle(): void
    {
        if ($this->busy()) {
            throw new ResourceOperationException('fleet.rollout_busy', 'A fleet Node visit is still open.', 409);
        }
    }
}
