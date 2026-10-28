<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Fleet\FleetRolloutPlanner;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Fleet\FleetRolloutVisits;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\FleetVisitPrerequisites;
use App\Domain\Fleet\FleetWindow;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Nodes\NodeCliInstaller;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Shared\ResourceOperationException;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\Node;
use App\Models\NodeFootprint as NodeFootprintRecord;
use Illuminate\Support\Facades\Cache;

/** Gateway state and admission gate for the Swamp rollout. Never runs a Node effect. */
final readonly class FleetRolloutPlanAction
{
    public function __construct(private DesiredFleetState $desired, private FleetRolloutPlanner $planner, private FleetRolloutMembership $membership,
        private FleetReleaseLag $lag, private FleetServingRelease $serving, private FleetRolloutAlerts $alerts, private FleetWindow $window, private NodeCliInstaller $cli, private NodeReachabilityProbe $reachability, private FleetRolloutVisits $visits, private FleetVisitPrerequisites $prerequisites) {}

    /** @return array{status: string, error_code?: string, rollout?: int, commit?: string, allow_downgrade?: bool, targets?: list<array{node_id: int, row_id: int, window: bool, attempts: int, finished_at: ?string, running: bool}>} */
    public function execute(): array
    {
        if (! config('fleet.rollout')) {
            return ['status' => 'disabled'];
        }

        $lock = Cache::lock(FleetRolloutVisits::AdmissionLock, 60);
        $lock->block(30);
        try {
            return $this->locked();
        } finally {
            $lock->release();
        }
    }

    /** @return array{status: string, error_code?: string, rollout?: int, commit?: string, allow_downgrade?: bool, targets?: list<array{node_id: int, row_id: int, window: bool, attempts: int, finished_at: ?string, running: bool}>} */
    private function locked(): array
    {
        if ($this->visits->busy()) {
            return ['status' => 'busy'];
        }
        try {
            $this->prerequisites->assertAvailable();
        } catch (ResourceOperationException $error) {
            return ['status' => 'unavailable', 'error_code' => $error->errorCode];
        }
        $halted = FleetRollout::query()->where('status', FleetRolloutStatus::Halted)->latest('id')->first();
        if ($halted !== null) {
            return ['status' => 'halted', 'rollout' => $halted->id];
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
        foreach (NodeFootprintRecord::query()->where('cli_state', 'foreign')->with('node.roles')->get() as $footprint) {
            $node = $footprint->node;
            if ($this->membership->exclusion($node) !== 'foreign_cli') {
                continue;
            }
            try {
                if ($this->reachability->degradation($node) === null && $this->cli->inspect($node) !== 'foreign') {
                    new NodeCliState()->clear($node);
                }
            } catch (\Throwable) {
                // An unreadable peer cannot prove that the foreign CLI was removed.
            }
        }
        $rollout = FleetRollout::query()->where('commit', $state->commit)->latest('id')->first();
        $open = $rollout !== null && $rollout->status !== FleetRolloutStatus::Superseded;
        if ($open && $rollout->status !== FleetRolloutStatus::Waiting && ! $state->cli->isAvailable()) {
            return ['status' => 'waiting', 'rollout' => $rollout->id];
        }
        $rollout = $open ? $this->planner->refresh($rollout, $state) : $this->planner->open($state, $gate['release']);
        if ($rollout->status === FleetRolloutStatus::Waiting) {
            if ($rollout->started_at?->diffInSeconds(now()) >= 7200 && $rollout->alert === null) {
                $this->alerts->waiting($rollout, $state);
            }

            return ['status' => 'waiting', 'rollout' => $rollout->id];
        }
        if ($state->cliFallback() && ! isset($rollout->notices['cli_fallback'])) {
            $rollout->forceFill(['notices' => [...($rollout->notices ?? []), 'cli_fallback' => $this->alerts->cliFallback($rollout, $state)]])->save();
        }
        $positions = array_flip(array_map(static fn (Node $node): int => $node->id, $this->membership->members()));
        $rows = $rollout->nodes()->with('node.roles')->get()->filter(fn (FleetRolloutNode $row): bool => $row->node !== null && $this->membership->includes($row->node)
            && ($row->outcome->awaitsCatchUp() || ($row->outcome->isConverged() && $this->lag->drifted($row, $row->node, $state))))->sortBy(static fn (FleetRolloutNode $row): array => [$row->node_id === null ? PHP_INT_MAX : ($positions[$row->node_id] ?? PHP_INT_MAX), $row->position]);
        $targets = [];
        foreach ($rows as $row) {
            $node = $row->node;
            if ($node === null) {
                continue;
            }
            $targets[] = ['node_id' => $node->id, 'row_id' => $row->id, 'window' => $this->window->allows($node), 'attempts' => $row->attempts,
                'finished_at' => $row->finished_at?->toIso8601String(), 'running' => $row->started_at !== null && $row->finished_at === null];
        }
        if ($rollout->status === FleetRolloutStatus::Running && ! $rollout->nodes()->where('outcome', FleetNodeOutcome::Pending)->exists()) {
            $rollout->forceFill(['status' => FleetRolloutStatus::Completed, 'finished_at' => now()])->save();
        }

        return ['status' => $rollout->status->value, 'rollout' => $rollout->id, 'commit' => $state->commit,
            'allow_downgrade' => $gate['state'] === 'verified', 'targets' => $targets];
    }
}
