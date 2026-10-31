<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Fleet\FleetRolloutPlanner;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Fleet\FleetServingRelease;
use App\Models\FleetRollout;

/** Reads desired intent and rollout records. Scheduling and visits belong to Swamp. */
final readonly class FleetRolloutPlanAction
{
    public function __construct(private DesiredFleetState $desired, private FleetRolloutPlanner $planner,
        private FleetRolloutMembership $membership, private FleetReleaseLag $lag, private FleetServingRelease $serving) {}

    /** @return array<string,mixed> */
    public function execute(): array
    {
        if (! config('fleet.rollout')) {
            return ['status' => 'disabled'];
        }
        $state = $this->desired->current();
        if ($state->commit === null) {
            return ['status' => 'commit_unknown'];
        }
        if ($this->serving->supersedes()) {
            return ['status' => 'superseded'];
        }
        $gate = $this->planner->gate($state->commit);
        if (! in_array($gate['state'], ['verified', 'in_place'], true)) {
            return ['status' => $gate['state'] === 'unverified' ? 'release_unverified' : 'release_layout_unreadable'];
        }
        $rollout = FleetRollout::query()->where('commit', $state->commit)->latest('id')->first();
        $rollout = $rollout === null || $rollout->status === FleetRolloutStatus::Superseded
            ? $this->planner->open($state, $gate['release']) : $this->planner->refresh($rollout, $state);
        if ($rollout->status === FleetRolloutStatus::Halted || ! $state->cli->isAvailable()) {
            return ['status' => $rollout->status->value, 'rollout' => $rollout->id];
        }
        $targets = [];
        foreach ($this->membership->members() as $node) {
            $row = $rollout->nodes()->where('node_id', $node->id)->firstOrFail();
            if (! $row->outcome->awaitsCatchUp() && ! ($row->outcome->isConverged() && $this->lag->drifted($row, $node, $state))) {
                continue;
            }
            $targets[] = ['node_id' => $node->id, 'row_id' => $row->id, 'attempts' => $row->attempts,
                'finished_at' => $row->finished_at?->toIso8601String()];
        }

        return ['status' => $rollout->status->value, 'rollout' => $rollout->id, 'commit' => $state->commit,
            'targets' => $targets];
    }
}
