<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
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
            $vm = $this->allocate->capture($group);
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
            $setup = ['instance_id' => $instance->id, 'project_id' => $project->id,
                'environment' => 'development', 'list' => 'setup',
                'execution' => ['variant' => 'checkout', 'user' => $node->user, 'directory' => $checkout->value, 'seed_path' => '', 'seed_commit' => ''],
                'steps' => array_map(static fn ($step): array => ['name' => $step->name,
                    'command_digest' => hash('sha256', $step->command), 'timeout_seconds' => $step->timeoutSeconds], $this->steps->ordered($project, LifecyclePhase::Setup))];
            $input = [...$input, 'group_id' => $group->id, 'instance_id' => $instance->id,
                'project_id' => $project->id, 'repository' => $project->repository_url, 'repository_identity' => $project->repository_identity,
                'git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $project->id],
                'direct_provisioning' => ['instance_id' => $instance->id, 'project_id' => $project->id,
                    'node_id' => $node->id, 'node_user' => $node->user, 'environment' => 'development',
                    'user' => $node->user, 'checkout' => $checkout->value, 'application' => $checkout->value,
                    'project_type' => $project->type->value, 'entry_point' => $project->type->frameworkEntryPoint(),
                    'skip_profile' => $project->type === ProjectType::Monorepo, 'php_versions' => $this->php->versions(),
                    'source' => ['repository' => $project->repository_url, 'checkout' => $checkout->value,
                        'root' => $sourceRoot->value, 'user' => $node->user, 'prepare_id' => $instance->source_prepare_id, 'seed_repository' => null],
                    'resolve' => ['instance_name' => $instance->name, 'default_branch' => $project->default_branch,
                        'branch_override' => $instance->branch_override, 'seed_commit' => null, 'network' => true,
                        'protocols' => config('orbit.git.checkout_protocols', ['https'])],
                    'setup' => $setup, 'already_active' => false, 'retained_source' => null]];
            $input['private_bindings'] = [...ValidatedData::object($input['private_bindings']), 'git_read' => ['owner_node_id' => $node->id, 'read_node_ids' => [$node->id], 'write_node_ids' => []]];
            $group->taskable()->associate($instance);
            $group->fill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now(), 'capacity_wait_reason' => null])->save();

            return $this->start->execute('tasks.provision', 'task_group', $group->id, ValidatedData::integer($input['node_id']), $input, $caller, $request);
        }));
    }
}
