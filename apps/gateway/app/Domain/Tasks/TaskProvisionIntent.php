<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Logs\LogRedactor;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One Tasks admission owns placement, enrollment and workspace intent. No child Operations. */
final readonly class TaskProvisionIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency,
        private TaskVmEnrollmentIntent $enrollment, private AllocateTaskVmAction $allocate,
        private TaskVmProvisionIntent $vms, private StorageRootResolver $roots,
        private NodeSettingsNormalizer $settings, private ManagedCheckoutOverlap $overlap,
        private ProjectLifecycleStepStore $steps, private InstancePhpVersionCatalog $php) {}

    public function start(Task $group, string $caller, ?string $request): Operation
    {
        if (($replay = $this->idempotency->replay('tasks.provision')) !== null) {
            return $replay;
        }

        return $this->enrollment->admit(fn (): Operation => DB::transaction(function () use ($group, $caller, $request): Operation {
            $group = Task::topLevel()->with('project')->lockForUpdate()->findOrFail($group->id);
            if (($replay = $this->idempotency->replay('tasks.provision')) !== null) {
                return $replay;
            }
            if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
                throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
            }
            if ($group->status === TaskGroupStatus::Backlog) {
                throw new ResourceOperationException('tasks.not_todo', 'Provision requires a todo task group.', 409);
            }
            if ($group->status !== TaskGroupStatus::Todo || $group->taskable_id !== null) {
                throw TaskGroupGuard::alreadyClaimed();
            }
            $project = $group->project;
            if ($project->task_workspace_routed || $project->processDefinitions()->exists() || $project->scheduleDefinitions()->exists()) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Task workspace publication and runtime definitions need their native parent steps.', 503);
            }
            try {
                $vm = $this->allocate->capture($group);
            } catch (TaskCapacityException $exception) {
                throw new ResourceOperationException('tasks.capacity_unavailable', $exception->getMessage(), 409,
                    previous: $exception, details: ['reason' => $exception->wait->value]);
            }
            $input = $this->vms->capture($vm);
            $node = $vm->node;
            assert($node !== null);
            $account = new ManagedUserAccount($node->user, $node->user, '/home/'.$node->user);
            $apps = $this->roots->resolveApps($this->settings->fromStored($node->settings), $account);
            $sourceRoot = $apps->append($project->slug);
            $checkout = $sourceRoot->append(TaskVmWorkspaceGuard::WorkspaceCheckout);
            $this->overlap->assertAvailable($node->id, $checkout, 'instance.path_taken');
            $instance = Instance::query()->create([
                'project_id' => $project->id, 'node_id' => $node->id, 'name' => TaskWorkspaceName::for($group),
                'source_layout' => InstanceSourceLayout::Checkout, 'source_prepare_id' => (string) Str::uuid(),
                'checkout_path' => $checkout->value, 'root' => null, 'branch_override' => TaskWorkspaceName::for($group),
                'task_workspace_routed' => false, 'task_workspace_owner_id' => $group->id,
                'status' => InstanceState::Reserved, 'first_setup_pending' => true,
            ]);
            $values = app(LogRedactor::class)->valuesFor($instance);
            $setup = array_map(function ($step) use ($values): array {
                if (app(LogRedactor::class)->redact($step->command, $values) !== $step->command) {
                    throw new ResourceOperationException('tasks.provision_refused', 'Setup commands must contain no credential values.', 422);
                }
                $command = $step->toArray();

                return [...$command, 'input_json' => json_encode($command, JSON_THROW_ON_ERROR)];
            }, $this->steps->ordered($project, LifecyclePhase::Setup));
            $copies = ValidatedData::object($input['execution_secrets']);
            $git = app(RepositoryReadAccess::class)->for($project->repository_url, $project->source_access);
            if (! $git->isEmpty()) {
                $copies['git_authorization'] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs',
                    'task-provision-git-'.$vm->id, $git->variables['GIT_CONFIG_VALUE_0']);
            }
            $input = [...$input, 'group_id' => $group->id, 'instance_id' => $instance->id,
                'project_id' => $project->id, 'eligible' => true,
                'workspace' => ['checkout_path' => $checkout->value, 'user' => $node->user,
                    'repository' => $project->repository_url, 'repository_identity' => $project->repository_identity,
                    'branch' => $instance->branch_override, 'default_branch' => $project->default_branch,
                    'project_type' => $project->type->value, 'entry_point' => $project->type->frameworkEntryPoint(),
                    'skip_profile' => $project->type === ProjectType::Monorepo, 'php_versions' => $this->php->versions(),
                    'setup' => $setup], 'execution_secrets' => $copies,
                'ssh_identity' => app(SwampSshExecutionInput::class)->identity()];
            $group->taskable()->associate($instance);
            $group->fill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now(), 'capacity_wait_reason' => null])->save();

            return $this->start->execute('tasks.provision', 'task_group', $group->id, ValidatedData::integer($input['node_id']), $input, $caller, $request);
        }));
    }
}
