<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/**
 * Release an issue's topology and prove its resources are gone.
 *
 * Every Incus resource is checked against the attempt (owner, issue, attempt) before deletion. The lease
 * names the declared Nodes, so a failed acquisition without a topology record is released exactly too.
 * The worktree's lease and record are dropped; the log stays. Every release ends with the orphan network sweep.
 */
final readonly class TopologyReleaser
{
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private ?OrphanNetworkSweep $sweep = null,
    ) {}

    /** @return array{state:string,issue:string,attempt_id:string,released:list<string>,already_absent:list<string>,networks_reaped:list<string>} */
    public function release(TopologyRequest $request): array
    {
        $state = IssueState::forWorktree($request->issue, $request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }
        try {
            if (! $state->hasAttempt()) {
                throw new RuntimeException("{$request->issue} has no active attempt.");
            }
            $target = $state->releaseTarget();
            [$released, $absent] = $this->deleteResources($target);
            $state->forgetAttempt();

            return [
                'state' => 'released',
                'issue' => $request->issue,
                'attempt_id' => $target->attempt->value,
                'released' => $released,
                'already_absent' => $absent,
                'networks_reaped' => $this->sweep?->sweep() ?? [],
            ];
        } finally {
            $lock->release();
        }
    }

    /** @return array{list<string>, list<string>} */
    private function deleteResources(TopologyTarget $target): array
    {
        $names = array_values($target->instances());
        $observed = $this->host->instances($names);
        $released = [];
        $absent = [];
        $running = [];
        $present = [];
        foreach (array_reverse($names) as $name) {
            $instance = $observed[$name] ?? null;
            if ($instance === null) {
                $absent[] = $name;

                continue;
            }
            $this->assertOwnership($instance->metadata, $target, $name);
            $present[] = $name;
            if ($instance->isRunning()) {
                $running[] = $name;
            }
        }
        $operator = $target->instance('operator');
        if (in_array($operator, $present, true)) {
            $this->host->stopWeb($operator);
        }
        if ($running !== []) {
            $this->host->forceStopAll($running);
            foreach ($running as $name) {
                $released[] = 'stopped:'.$name;
            }
        }
        if ($present !== []) {
            $this->host->deleteInstances($present);
            foreach ($present as $name) {
                $released[] = 'deleted:'.$name;
            }
        }
        if ($this->host->instances($names) !== []) {
            throw new RuntimeException('Exact topology guests remain after release deletion.');
        }

        $network = $this->host->network($target->network());
        if ($network === null) {
            $absent[] = $target->network();
        } else {
            $this->assertOwnership($network->metadata, $target, $target->network());
            $this->networks->delete($target->network());
            $released[] = 'deleted:'.$target->network();
            if ($this->host->network($target->network()) !== null) {
                throw new RuntimeException('The topology network remains after release deletion.');
            }
        }

        return [$released, $absent];
    }

    /** @param array<string, string> $metadata */
    private function assertOwnership(array $metadata, TopologyTarget $target, string $resource): void
    {
        if (
            ($metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($metadata['user.orbit.e2e.issue'] ?? null) !== $target->issue
            || ($metadata['user.orbit.e2e.attempt'] ?? null) !== $target->attempt->value
        ) {
            throw new RuntimeException("Incus resource {$resource} ownership does not match the issue attempt.");
        }
    }
}
