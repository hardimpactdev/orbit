<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\Deployment\DevelopmentDeploySteps;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCopyLifecycle;
use App\Domain\Tasks\TaskCopyStart;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Config;

/**
 * The runs of `proof:run` in copies of the proof Project's default Instance (docs/cli/proof/run.mdx#the-run): how many copies a proof
 * may take, whether a run's copy is ready for its start, and the run's workflow input when it starts. The copy itself is a task copy
 * (TaskCopyStart) that the run's group owns; its end is the group's cancel (ProofGroup).
 */
final readonly class ProofCopies
{
    /** The copy places a proof always leaves free for task groups. */
    public const int KeptForTasks = 2;

    /** The step and capacity reason of a proof that waits for a copy place. */
    public const string WaitStep = 'Wait for a free copy place';

    public function __construct(private ProofSettings $settings) {}

    /** Whether proofs of this Project run in copies: its tasks run as copies of its default. */
    public static function runs(Project $project): bool
    {
        return InstancePlacement::copiesTasks($project);
    }

    /** The copy places a proof may take now: at most `proof.copies`, and never the last two copy places of the default's Node. */
    public function places(Project $project): int
    {
        $default = Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->first();
        $live = $default === null ? 0 : TaskCopyStart::live($default->node_id);

        return max(0, min($this->settings->copies(), Config::integer('orbit.tasks.copies_max') - $live - self::KeptForTasks));
    }

    /** The copy places a proof would have with no other copy on the Node. */
    public function mostPlaces(): int
    {
        return max(0, min($this->settings->copies(), Config::integer('orbit.tasks.copies_max') - self::KeptForTasks));
    }

    /** Whether the run of `proof.command` proves in a copy. */
    public static function isCopyRun(Operation $run): bool
    {
        return ($run->input['copy'] ?? false) === true;
    }

    /**
     * Whether a held run of a copy may start: `ready` when its copy is active, `pending` while the copy's create or completion runs,
     * `failed` when the copy's completion failed or the copy is gone (a removal ends with the copy gone), and for a run that is not in a
     * copy: one admitted before the task VM proof path was removed, which nothing can start any more.
     */
    public function state(Operation $run): string
    {
        if (! self::isCopyRun($run)) {
            return 'failed';
        }
        $group = Task::topLevel()->with('taskable')->find(ValidatedData::integer($run->input['group_id']));
        if ($group === null || in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            return 'failed';
        }
        $copy = TaskInstanceWorkspace::forGroup($group);
        if ($copy === null || TaskCopyLifecycle::completionFailure($copy->instance) !== null) {
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
        foreach (ProofBatchPlan::stored($input['assignments']) as $index => $assignment) {
            $verifications[] = [...$verify, 'command' => $assignment['command'], 'file' => $assignment['file'],
                'batch' => $assignment['batch'], ...($assignment['cases'] === [] ? [] : ['case_ids' => $assignment['cases']]),
                'step' => 'Prove assigned batches-'.$index, 'restore_first' => true];
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
