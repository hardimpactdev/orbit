<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Throwable;

/**
 * Owns the bounded child-process state machine for multi-node Doctor checks.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class DoctorFleetProbeExecutor
{
    public const int BATCH_SIZE = 5;

    private const int POLL_INTERVAL_MICROSECONDS = 50_000;

    public function __construct(
        private DoctorNodeFamilyResolver $nodeFamilies,
        private DoctorFleetTargetProbe $fleetTargetProbe,
        private DoctorFleetNodeProjection $fleetNodeProjection,
        private DoctorFleetProbeWorker $fleetProbeWorker,
        private DoctorFleetProgressReporter $progressReporter,
    ) {}

    public function execute(FleetProbeRunState $state): void
    {
        if ($this->fleetProbeWorker->canRun()) {
            $this->executeConcurrently($state);

            return;
        }

        $this->executeSequentially($state);
    }

    private function executeSequentially(FleetProbeRunState $state): void
    {
        foreach ($state->scope->targets as $nodeIndex => $node) {
            $this->executeTarget(
                node: $node,
                nodeIndex: $nodeIndex,
                state: $state,
            );
        }
    }

    private function executeConcurrently(FleetProbeRunState $state): void
    {
        $nodeList = array_values($state->scope->targets->all());
        /** @var array<int, int> $doneFamiliesByWorkerIndex */
        $doneFamiliesByWorkerIndex = [];
        /** @var array<int, array{node: Node, process: InvokedProcess, outputBuffer: string, onFamilyProgress: callable}> $workers */
        $workers = [];
        $nextIndex = 0;

        while ($nextIndex < count($nodeList) || $workers !== []) {
            $this->startWorkers($nodeList, $workers, $doneFamiliesByWorkerIndex, $nextIndex, $state);
            $this->completeStoppedWorkers($workers, $doneFamiliesByWorkerIndex, $state);

            if ($workers !== []) {
                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }
        }

        $state->nodes = $this->fleetNodeProjection->orderedNodeSummaries(
            $state->scope->targets,
            $state->nodesByIndex,
        );
        $state->issues = $this->fleetNodeProjection->orderedIssues(
            $state->scope->targets,
            $state->issuesByIndex,
        );
    }

    /**
     * @param  list<Node>  $nodeList
     * @param  array<int, array{node: Node, process: InvokedProcess, outputBuffer: string, onFamilyProgress: callable}>  $workers
     * @param  array<int, int>  $doneFamiliesByWorkerIndex
     */
    private function startWorkers(
        array $nodeList,
        array &$workers,
        array &$doneFamiliesByWorkerIndex,
        int &$nextIndex,
        FleetProbeRunState $state,
    ): void {
        while (count($workers) < self::BATCH_SIZE && $nextIndex < count($nodeList)) {
            $node = $nodeList[$nextIndex];
            $state->nodeProgressStatuses[$nextIndex]['status'] = 'running';

            if ($state->scope->onNodeProgress !== null) {
                $runningPhase = 'running';
                ($state->scope->onNodeProgress)($node, $runningPhase);
            }

            $process = $this->fleetProbeWorker->start($node, $state->scope->families, $state->scope->key);

            if ($process === null) {
                $this->progressReporter->complete(
                    node: $node,
                    nodeIndex: $nextIndex,
                    state: $state,
                    report: $this->fleetTargetProbe->probe($node, $state->scope->families, $state->scope->key),
                );
                $nextIndex++;

                continue;
            }

            $doneFamiliesByWorkerIndex[$nextIndex] = 0;
            $workers[$nextIndex] = [
                'node' => $node,
                'process' => $process,
                'outputBuffer' => '',
                'onFamilyProgress' => $this->progressReporter->familyProgressReporter(
                    node: $node,
                    nodeIndex: $nextIndex,
                    totalFamilies: count($this->nodeFamilies->selectedFamiliesForNode($node, $state->scope->families)),
                    doneFamilies: $doneFamiliesByWorkerIndex[$nextIndex],
                    state: $state,
                ),
            ];
            $nextIndex++;
        }
    }

    /**
     * @param  array<int, array{node: Node, process: InvokedProcess, outputBuffer: string, onFamilyProgress: callable}>  $workers
     * @param  array<int, int>  $doneFamiliesByWorkerIndex
     */
    private function completeStoppedWorkers(
        array &$workers,
        array &$doneFamiliesByWorkerIndex,
        FleetProbeRunState $state,
    ): void {
        foreach (array_keys($workers) as $index) {
            $worker = $workers[$index];

            try {
                if (method_exists($worker['process'], 'ensureNotTimedOut')) {
                    $worker['process']->ensureNotTimedOut();
                }

                $running = $worker['process']->running();
                $this->fleetProbeWorker->drainProgress(
                    process: $workers[$index]['process'],
                    outputBuffer: $workers[$index]['outputBuffer'],
                    onFamilyProgress: $workers[$index]['onFamilyProgress'],
                );

                if ($running) {
                    continue;
                }

                $report = $this->resolveProcessReport(
                    node: $worker['node'],
                    process: $worker['process'],
                    families: $state->scope->families,
                    key: $state->scope->key,
                );
            } catch (ProcessTimedOutException) {
                $report = $this->fleetTargetProbe->probe(
                    node: $worker['node'],
                    families: $state->scope->families,
                    key: $state->scope->key,
                );
            }

            $this->progressReporter->complete(
                node: $worker['node'],
                nodeIndex: $index,
                state: $state,
                report: $report,
            );
            unset($workers[$index]);
            unset($doneFamiliesByWorkerIndex[$index]);
        }
    }

    private function executeTarget(Node $node, int $nodeIndex, FleetProbeRunState $state): void
    {
        $state->nodeProgressStatuses[$nodeIndex]['status'] = 'running';

        if ($state->scope->onNodeProgress !== null) {
            $runningPhase = 'running';
            ($state->scope->onNodeProgress)($node, $runningPhase);
        }

        $doneFamilies = 0;
        $onFamilyProgress = $this->progressReporter->familyProgressReporter(
            node: $node,
            nodeIndex: $nodeIndex,
            totalFamilies: count($this->nodeFamilies->selectedFamiliesForNode($node, $state->scope->families)),
            doneFamilies: $doneFamilies,
            state: $state,
        );

        $this->progressReporter->complete(
            node: $node,
            nodeIndex: $nodeIndex,
            state: $state,
            report: $this->fleetTargetProbe->probe(
                node: $node,
                families: $state->scope->families,
                key: $state->scope->key,
                onFamilyProgress: $onFamilyProgress,
            ),
        );
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function resolveProcessReport(
        Node $node,
        InvokedProcess $process,
        array $families,
        ?string $key,
    ): array {
        try {
            $result = $process->wait();
        } catch (Throwable) {
            return $this->fleetTargetProbe->probe($node, $families, $key);
        }

        if (! $result->successful()) {
            return $this->fleetTargetProbe->probe($node, $families, $key);
        }

        $report = $this->fleetProbeWorker->decodeReport($result->output());

        if ($report === null) {
            return $this->fleetTargetProbe->probe($node, $families, $key);
        }

        try {
            return $this->fleetProbeWorker->canonicalizeReport($report);
        } catch (Throwable) {
            return $this->fleetTargetProbe->probe($node, $families, $key);
        }
    }
}
