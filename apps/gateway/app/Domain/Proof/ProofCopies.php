<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Ci\ShowCiRunnersAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\Deployment\DevelopmentDeploySteps;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCopyLifecycle;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Infrastructure\Metrics\PrometheusCiRunnerStates;
use App\Infrastructure\Metrics\PrometheusMemAvailable;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Support\ValidatedData;

/**
 * The runs of `proof:run` in copies of the proof Project's default Instance (docs/cli/proof/run.mdx#the-run): the free CI places a proof
 * may take on each Node, whether a run's copy is ready for its start, and the run's workflow input when it starts. The copy itself is a
 * task copy (TaskCopyStart) that the run's group owns; its end is the group's cancel (ProofGroup).
 *
 * @phpstan-import-type CiNodeRunnerStates from PrometheusCiRunnerStates
 *
 * @phpstan-type ProofPlaceReads array{states: array<string, CiNodeRunnerStates>|null, memory: array<string, int>|null}
 */
final readonly class ProofCopies
{
    /** The step and capacity reason of a proof that waits for a free CI place. */
    public const string WaitStep = 'Wait for a free CI place';

    public function __construct(private ProofSettings $settings, private PrometheusCiRunnerStates $states, private PrometheusMemAvailable $memory) {}

    /** Whether proofs of this Project run in copies: its tasks run as copies of its default. */
    public static function runs(Project $project): bool
    {
        return InstancePlacement::copiesTasks($project);
    }

    /**
     * The runner state and the available memory of every Node, from Prometheus. Read them before the admission transaction.
     *
     * @return ProofPlaceReads
     */
    public function read(): array
    {
        return ['states' => $this->states->states(), 'memory' => $this->memory->bytes()];
    }

    /**
     * The CI places a proof may take now on each Node, by Node id, Nodes without one left out: its free places for new copies
     * (freePlaces) and its warm copies that a proof may take (ProofWarmPool::takeable), which already hold their places.
     *
     * @param  ProofPlaceReads  $read
     * @return array<int, int>
     */
    public function places(Project $project, array $read): array
    {
        $places = $this->freePlaces($project, $read);
        foreach (app(ProofWarmPool::class)->takeable() as $nodeId => $warm) {
            $places[$nodeId] = ($places[$nodeId] ?? 0) + $warm;
        }
        ksort($places);

        return $places;
    }

    /**
     * The free CI places for a new copy on each Node, by Node id, Nodes without one left out. A Node counts when it holds the
     * default's current seed: the default's own Node holds it. Its places are its free CI places
     * (ProofCopyHosts::freePlaces), and no more than its available memory holds copy peaks. A Node without a fresh runner state or a
     * memory read offers none.
     *
     * @param  ProofPlaceReads  $read
     * @return array<int, int>
     */
    public function freePlaces(Project $project, array $read): array
    {
        return $this->count($project, $read, false);
    }

    /**
     * The Node of each new copy, one copy per place: the Nodes take turns, so fewer copies than places spread over the Nodes.
     *
     * @param  array<int, int>  $places
     * @return list<int>
     */
    public static function spread(array $places, int $copies): array
    {
        $nodes = [];
        $copies = min($copies, array_sum($places));
        while (count($nodes) < $copies) {
            foreach ($places as $nodeId => $free) {
                if ($free > 0 && count($nodes) < $copies) {
                    $nodes[] = $nodeId;
                    $places[$nodeId] = $free - 1;
                }
            }
        }

        return $nodes;
    }

    /**
     * The places the proof would have with no busy runner and no other proof copy on any Node, its available memory as read.
     *
     * @param  ProofPlaceReads  $read
     */
    public function mostPlaces(Project $project, array $read): int
    {
        return array_sum($this->count($project, $read, true));
    }

    /**
     * @param  ProofPlaceReads  $read
     * @return array<int, int>
     */
    private function count(Project $project, array $read, bool $allFree): array
    {
        $default = Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->first();
        if ($default === null || $read['states'] === null || $read['memory'] === null) {
            return [];
        }
        $nodes = Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($roles) => $roles->where('role', RoleName::CiRunner)->where('status', '!=', LifecycleStatus::Removing))
            ->orderBy('id')->get()
            ->filter(static fn (Node $node): bool => ($node->id === $default->node_id || app(ProofSeeds::class)->holder($node) !== null));
        $copies = ProofCopyHosts::copies()->whereIn('node_id', $nodes->modelKeys())->pluck('node_id')->countBy();
        $fresh = now()->getTimestamp() - ShowCiRunnersAction::StaleSeconds;

        $places = [];
        foreach ($nodes as $node) {
            $state = $read['states'][$node->name] ?? null;
            $memory = $read['memory'][$node->name] ?? null;
            $ci = app(NodeSettingsNormalizer::class)->fromStored($node->settings)?->ci();
            if ($state === null || ! $state['read_ok'] || ($state['observed_at'] ?? 0) < $fresh || $memory === null || $ci === null) {
                continue;
            }
            $busy = count(array_filter($state['runners'], static fn (array $runner): bool => $runner['busy']));
            $free = min($allFree ? ProofCopyHosts::freePlaces($node, $ci, 0, 0) : ProofCopyHosts::freePlaces($node, $ci, $busy, $copies->get($node->id, 0)),
                ProofCopyHosts::memoryPlaces($memory));
            if ($free > 0) {
                $places[$node->id] = $free;
            }
        }

        return $places;
    }

    /** Whether the run of `proof.command` proves in a copy. */
    public static function isCopyRun(Operation $run): bool
    {
        return ($run->input['copy'] ?? false) === true;
    }

    /**
     * Whether a held run of a copy may start: `ready` when its copy is active, `pending` while the copy's create or completion runs,
     * `failed` when the copy's create ended without success, its completion failed or the copy is gone (a removal ends with the copy
     * gone), and for a run that is not in a copy: one admitted before the task VM proof path was removed, which nothing can start any
     * more. A create whose budget expired leaves its group reserved (TaskCopyLifecycle::createEnded), but no create follows it for a
     * proof's copy: once the expiry is settled (Swamp proved the run's end, or releaseStaleHolds marked it unproven) the run fails, and
     * its group's cancel destroys the copy and releases its CI place. Until then the run stays pending: Swamp reopens an expiry whose
     * run met its real budget (ExpireOperationAction::reopen).
     */
    public function state(Operation $run): string
    {
        if (! self::isCopyRun($run)) {
            return 'failed';
        }
        $group = Task::topLevel()->with('taskable')->find(ValidatedData::integer($run->input['group_id']));

        return $group === null ? 'failed' : self::copyState($group);
    }

    /** Whether the copy of the group is `ready`, `pending` or `failed`, by the rule of state(): a warm copy follows it too. */
    public static function copyState(Task $group): string
    {
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            return 'failed';
        }
        $copy = TaskInstanceWorkspace::forGroup($group);
        if ($copy === null || TaskCopyLifecycle::completionFailure($copy->instance) !== null) {
            return 'failed';
        }
        $create = Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')->where('target_id', $copy->instance->id)
            ->orderByDesc('id')->first();
        if ($create !== null && $create->status->terminal() && $create->status !== OperationStatus::Succeeded
            && ! ExpireOperationAction::expiredUnsettled($create)) {
            return 'failed';
        }

        return $copy->instance->status === InstanceState::Active ? 'ready' : 'pending';
    }

    /**
     * The workflow input of a run in a copy, built when the run starts, so the copy is active: the commit and where to fetch it, the
     * copy's guest and fleet for each assigned batch, and the Project's development deploy steps. With `$copies`, each step's command
     * goes to its own vault key; a public read passes false and writes nothing.
     *
     * @return array<string,mixed>
     */
    public function input(Operation $run, bool $copies): array
    {
        $input = ValidatedData::object($run->input);
        $group = Task::topLevel()->with('taskable', 'project')->find(ValidatedData::integer($input['group_id']))
            ?? throw new ResourceOperationException('tasks.group_missing', 'The task group of the proof run is gone.', 409);
        $copy = TaskInstanceWorkspace::forGroup($group)
            ?? throw new ResourceOperationException('tasks.no_workspace', 'The task group of the proof run owns no copy.', 409);
        $verify = app(ProofVerifyIntent::class)->captureCopy($group, $copy, ValidatedData::string($input['command']));
        $verifications = [];
        // The first batch resets the fleet, and so does each batch after one that could change it: a read-only batch leaves it at `base`.
        $base = false;
        foreach (ProofBatchPlan::stored($input['assignments']) as $index => $assignment) {
            $verifications[] = [...$verify, 'command' => $assignment['command'], 'file' => $assignment['file'],
                'batch' => $assignment['batch'], ...($assignment['cases'] === [] ? [] : ['case_ids' => $assignment['cases']]),
                'step' => 'Prove assigned batches-'.$index, 'restore_first' => ! $base];
            $base = $assignment['read_only'];
        }
        $steps = app(DevelopmentDeploySteps::class)->capture($group->project);
        $repository = $this->settings->repository();

        return [
            'intent' => [
                'commit' => ValidatedData::string($input['commit']),
                'checkout' => ['container' => $verify['container'], 'ssh_execution' => $verify['ssh_execution'], 'commit' => ValidatedData::string($input['commit']),
                    'repository' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git', 'deployment' => ['steps' => $steps]],
                'verifications' => $verifications,
            ],
            'execution_secrets' => $copies ? app(DevelopmentDeploySteps::class)->copies($run, $group->project, $steps) : [],
        ];
    }
}
