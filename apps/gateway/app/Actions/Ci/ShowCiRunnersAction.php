<?php

declare(strict_types=1);

namespace App\Actions\Ci;

use App\Actions\Nodes\ShowNodeMetricsAction;
use App\Data\Ci\CiCurrentJobData;
use App\Data\Ci\CiJobData;
use App\Data\Ci\CiNodeData;
use App\Data\Ci\CiNodeResourcesData;
use App\Data\Ci\CiPlacesData;
use App\Data\Ci\CiProofCopyData;
use App\Data\Ci\CiRunnerData;
use App\Data\Ci\CiRunnersData;
use App\Data\Ci\CiRunnerStateData;
use App\Data\Ci\CiRunnerStatsData;
use App\Data\Ci\CiUsageData;
use App\Data\Nodes\NodeCiData;
use App\Domain\Instances\InstancePlacement;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Proof\ProofGroup;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\GitHub\GitHubActionsJobs;
use App\Infrastructure\Metrics\PrometheusCiRunnerStates;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use Carbon\CarbonImmutable;

/**
 * The CI runner view (docs/reference/ci-runner-view.md): each CI Node's places, resources and runners, and the self-hosted jobs of
 * the window. It only reads: runner state from Prometheus, proof copies from the Gateway's records, resources from the Node agent's
 * snapshot and jobs from GitHub, each through its own cache. A source that cannot answer marks its part and fails nothing.
 *
 * @phpstan-import-type CiNodeRunnerStates from PrometheusCiRunnerStates
 */
final readonly class ShowCiRunnersAction
{
    /** A runner state reading older than this is stale. */
    public const int StaleSeconds = 60;

    /** Conclusions that count as a failed job. */
    private const array Failures = ['failure', 'timed_out', 'startup_failure'];

    public function __construct(
        private PrometheusCiRunnerStates $states,
        private GitHubActionsJobs $github,
        private ShowNodeMetricsAction $metrics,
        private NodeSettingsNormalizer $settings,
    ) {}

    public function execute(): CiRunnersData
    {
        $now = CarbonImmutable::now();
        $nodes = Node::query()->whereHas('roles', static fn ($roles) => $roles->where('role', RoleName::CiRunner)->where('status', LifecycleStatus::Active))
            ->orderBy('name')->get();
        $jobs = $this->github->read();
        $states = $nodes->isEmpty() ? [] : $this->states->states();
        $copies = $this->proofCopies($nodes->map(static fn (Node $node): int => $node->id)->all());
        $facts = NodeFact::query()->whereIn('node_id', $nodes->modelKeys())->where('kind', NodeFactKind::Metrics)->get()->keyBy('node_id');

        $views = [];
        foreach ($nodes as $node) {
            $state = $states === null ? null : ($states[$node->name] ?? null);
            $runners = $state !== null && $state['read_ok'] ? $this->runners($state, $jobs->jobs) : [];
            $own = $copies[$node->id] ?? [];
            $busy = count(array_filter($runners, static fn (CiRunnerData $runner): bool => $runner->busy));
            $fact = $facts->get($node->id);

            $views[] = new CiNodeData(
                node_id: $node->id,
                node_name: $node->name,
                places: self::places($this->settings->fromStored($node->settings)?->ci(), $busy, count($own)),
                proof_copies: $own,
                resources: $fact instanceof NodeFact ? $this->resources($node, $fact) : null,
                runner_state: $this->runnerState($state, $now),
                runners: $runners,
            );
        }

        return new CiRunnersData($now->utc()->toIso8601ZuluString(), GitHubActionsJobs::WindowHours, $views,
            $jobs->repositories, $jobs->github_error_code, $jobs->jobs);
    }

    /**
     * The live copies that proof runs' task groups own, by Node.
     *
     * @param  array<int, int>  $nodeIds
     * @return array<int, list<CiProofCopyData>>
     */
    private function proofCopies(array $nodeIds): array
    {
        $copies = [];
        $instances = Instance::query()->whereIn('node_id', $nodeIds)->whereNotNull('task_workspace_owner_id')
            ->whereHas('runtime', static fn ($runtime) => $runtime->where('incus_project', InstancePlacement::NestedProject))
            ->whereIn('task_workspace_owner_id', Operation::query()->select('target_id')->where('kind', ProofGroup::OwnerKind)->where('target_type', 'task_group'))
            ->orderBy('name')->get(['id', 'node_id', 'name', 'task_workspace_owner_id']);

        foreach ($instances as $instance) {
            $copies[$instance->node_id][] = new CiProofCopyData($instance->id, $instance->name, (int) $instance->task_workspace_owner_id);
        }

        return $copies;
    }

    /** @param CiNodeRunnerStates|null $state */
    private function runnerState(?array $state, CarbonImmutable $now): CiRunnerStateData
    {
        $observed = $state['observed_at'] ?? null;

        return new CiRunnerStateData(
            observed_at: $observed === null ? null : CarbonImmutable::createFromTimestampUTC($observed)->toIso8601ZuluString(),
            stale: $observed !== null && $now->getTimestamp() - $observed > self::StaleSeconds,
            error_code: $state !== null && $state['read_ok'] ? null : 'ci_runner.state_unavailable',
        );
    }

    /**
     * @param  CiNodeRunnerStates  $state
     * @param  list<CiJobData>  $jobs
     * @return list<CiRunnerData>
     */
    private function runners(array $state, array $jobs): array
    {
        $runners = [];

        foreach ($state['runners'] as $name => $runner) {
            $own = array_values(array_filter($jobs, static fn (CiJobData $job): bool => $job->runner_name === $name));
            $current = array_find($own, static fn (CiJobData $job): bool => $job->status === 'in_progress');
            $done = array_values(array_filter($own, static fn (CiJobData $job): bool => $job->status === 'completed'));
            $runs = array_values(array_filter(array_map(static fn (CiJobData $job): ?int => $job->run_seconds, $done), is_int(...)));

            $runners[] = new CiRunnerData(
                name: $name,
                state: $runner['state'],
                service: $runner['service'],
                busy: $runner['busy'],
                version: $runner['version'],
                current_job: $current === null ? null
                    : new CiCurrentJobData($current->job_id, $current->repository, $current->workflow, $current->job, $current->started_at, $current->url),
                stats: new CiRunnerStatsData(
                    jobs: count($done),
                    failed: count(array_filter($done, static fn (CiJobData $job): bool => in_array($job->conclusion, self::Failures, true))),
                    queue_wait_seconds_median: self::median(array_values(array_filter(array_map(static fn (CiJobData $job): ?int => $job->queue_wait_seconds, $done), is_int(...)))),
                    run_seconds_median: self::median($runs),
                    busy_seconds: array_sum($runs),
                ),
            );
        }

        return $runners;
    }

    private function resources(Node $node, NodeFact $fact): CiNodeResourcesData
    {
        $metrics = $this->metrics->fromFact($node, $fact);
        $root = array_find($metrics->disks, static fn (array $disk): bool => $disk['mount'] === '/');

        return new CiNodeResourcesData(
            observed_at: $metrics->observedAt,
            possibly_outdated: $metrics->possiblyOutdated,
            cpu_pressure: $metrics->pressure['cpu']['some_avg10'],
            memory_pressure: $metrics->pressure['memory']['some_avg10'],
            memory: new CiUsageData($metrics->memory['used'], $metrics->memory['total']),
            disk: $root === null || $root['used'] === null || $root['total'] === null ? null : new CiUsageData($root['used'], $root['total']),
        );
    }

    /** The Node's `ci` setting counts its places; on a proof-only Node a runner job uses none of them. */
    private static function places(?NodeCiData $ci, int $busyRunners, int $proofCopies): CiPlacesData
    {
        $runnerJobs = $ci?->proof_only === true ? 0 : $busyRunners;
        $total = $ci?->places;

        return new CiPlacesData($total, $ci?->proof_only ?? false, $runnerJobs, $proofCopies,
            $total === null ? null : max(0, $total - $runnerJobs - $proofCopies));
    }

    /** @param list<int> $values */
    private static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);
    }
}
