<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Operations\StartOperationAction;
use App\Actions\Routes\CreateRouteAction;
use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Logs\LogRedactor;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmConvergeIntent;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmReadyCopiesIntent;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One Tasks admission owns placement, enrollment and workspace intent. No child Operations. */
final readonly class TaskProvisionIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency,
        private TaskVmEnrollmentIntent $enrollment, private AllocateTaskVmAction $allocate,
        private TaskVmProvisionIntent $vms, private StorageRootResolver $roots,
        private NodeSettingsNormalizer $settings, private ManagedCheckoutOverlap $overlap,
        private ProjectLifecycleStepStore $steps, private InstancePhpVersionCatalog $php,
        private TaskStartCommit $startCommit, private TaskVmSlotPool $slots) {}

    /** A proof starts its group at the commit it proves; any other group starts at the newest green commit of the default branch. */
    public function start(Task $group, string $caller, ?string $request, ?string $commit = null): Operation
    {
        return $this->admit($group, $commit, 'tasks.provision', static fn (array $input): array => $input, $caller, $request);
    }

    /**
     * Captures the provision intent in one admission transaction and records it as the Operation of `$kind`. A kind
     * that runs provision as one step of a larger run, such as `tasks.prep`, adds its own intent through `$extend`,
     * which receives the provision intent, the group, its workspace Instance and its VM, and returns the whole input of
     * the Operation; the node of the Operation stays the VM host of the provision intent. `$host` pins the VM to one task VM
     * host, and `$size` sets its size, as the refresh of that host's task image does.
     *
     * @param  Closure(array<string,mixed>, Task, Instance, TaskVm): array<string,mixed>  $extend
     */
    public function admit(Task $group, ?string $commit, string $kind, Closure $extend, string $caller, ?string $request, ?int $host = null, ?TaskVmSize $size = null): Operation
    {
        if (($replay = $this->idempotency->replay($kind)) !== null) {
            return $replay;
        }
        // A group that cannot be provisioned is refused before any GitHub read; the locked recheck below stays.
        $this->assertProvisionable($group);
        // GitHub reads stay outside the admission transaction; the workspace starts exactly at this commit.
        $startCommit = $commit ?? $this->startCommit->resolve($group->project()->firstOrFail());

        return $this->enrollment->admit(fn (): Operation => DB::transaction(function () use ($group, $caller, $request, $startCommit, $commit, $kind, $extend, $host, $size): Operation {
            $group = Task::topLevel()->with('project')->lockForUpdate()->findOrFail($group->id);
            if (($replay = $this->idempotency->replay($kind)) !== null) {
                return $replay;
            }
            $this->assertProvisionable($group);
            $project = $group->project;
            // A routed workspace renders its Route from the Project's source facts, as instance:create does, so they must be valid.
            if ($project->type->isWebServing() && $project->task_workspace_routed && $project->source_profile_error !== null) {
                throw new ResourceOperationException($project->source_profile_error, 'The Project source metadata is invalid or unsupported for a routed task workspace.', 422);
            }
            // Project 62 and the group of a `proof:run` (the one provision with an explicit commit) get the proof club key, and no other task.
            $proof = ($project->id === 62 && $project->slug === 'swamp-proof') || $commit !== null;
            $proofClub = $proof ? config('swamp.proof_club_api_key') : null;
            if ($proof && (! is_string($proofClub) || trim($proofClub) === '')) {
                throw new ResourceOperationException('tasks.proof_credential_unavailable', 'A proof requires its Gateway-held sandbox club credential before placement.', 503);
            }
            try {
                // Stage 2 "Take a task VM": a ready slot, else a clone. A host-pinned provision (an image refresh) always clones.
                $slot = $host === null ? $this->slots->claim($group) : null;
                $vm = $slot ?? $this->allocate->capture($group, $host, $size);
            } catch (TaskCapacityException $exception) {
                throw new ResourceOperationException('tasks.capacity_unavailable', $exception->getMessage(), 409,
                    previous: $exception, details: ['reason' => $exception->wait->value]);
            }
            $input = $slot !== null ? $this->slots->claimInput($slot) : $this->vms->capture($vm);
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
                'task_workspace_routed' => $routed = $project->type->isWebServing() && $project->task_workspace_routed, 'task_workspace_owner_id' => $group->id,
                'status' => InstanceState::Reserved, 'first_setup_pending' => true,
                // Admitted Project facts, as instance:create records them: the Route site renders from them before the run.
                'selected_php_version' => $project->php_version, 'source_is_laravel' => $project->is_laravel === true,
            ]);
            // The guest's host key is pinned only by this run's VM step; its SSH targets carry `host_keys: null`
            // until the workflow binds them. The Route comes first: it changes how Process units render.
            [$route, $routeInput, $members] = app(PendingSshHostKey::class)->during($node->id, function () use ($instance, $routed, $node): array {
                $route = $routed ? app(CreateRouteAction::class)->ensureForInstance($instance, null) : null;
                app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForDevelopment($instance);

                return [$route, $route === null ? null : app(NativeRouteAdmission::class)->capture('route.create', $route, ['mode' => 'converge'], $node->id,
                    // The run routes the workspace after its setup, so the Route publishes it served, not the 503 of a reserved
                    // Instance, and keeps the .env the setup wrote; the Gateway's later render then matches what was published.
                    preserveEnvironment: true, preparedWorkloads: true),
                    app(ProcessInstanceOperation::class)->capture($instance, 'process.install_instance_definitions')['members']];
            });
            $values = app(LogRedactor::class)->valuesFor($instance);
            $setup = array_map(function ($step) use ($values): array {
                if (app(LogRedactor::class)->redact($step->command, $values) !== $step->command) {
                    throw new ResourceOperationException('tasks.provision_refused', 'Setup commands must contain no credential values.', 422);
                }
                $command = $step->toArray();

                return [...$command, 'input_json' => json_encode($command, JSON_THROW_ON_ERROR)];
            }, $this->steps->ordered($project, LifecyclePhase::Setup));
            // A claimed slot converged at its enrol; it converges again only when the release changed playbooks, pins or lock files since.
            $converge = $slot !== null && $slot->slot_digest === $this->slots->releaseDigest() ? null : $this->converge($vm, $node, $input);
            // The VM child takes `execution_secrets` whole; the workspace's own vault references travel in the intent.
            $proofKey = $proof ? app(SwampCommandStep::class)->executionCopy('orbit-control-inputs',
                'swamp-proof-club-api-key', $proofClub) : null;
            // Only the vault key name is admitted. The clone step mints the credential when it runs ({@see TaskGitHubTokens}):
            // the VM step before it can wait for capacity longer than the hour that an installation token lives.
            $gitAuthorization = ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-git-'.$vm->id];
            // The setup steps get the same: the key names of the Composer and npm configuration, written when the step runs.
            $dependencyAuth = TaskGitHubTokens::dependencyAuth($vm);
            $input = [...$input, 'group_id' => $group->id, 'instance_id' => $instance->id,
                'project_id' => $project->id, 'eligible' => true, ...($proof ? ['proof_club_required' => true] : []),
                'proof_club' => $proofKey, 'git_authorization' => $gitAuthorization, 'dependency_auth' => $dependencyAuth,
                'workspace' => ['checkout_path' => $checkout->value, 'user' => $node->user,
                    'repository' => $project->repository_url, 'repository_identity' => $project->repository_identity,
                    'branch' => $instance->branch_override, 'default_branch' => $project->default_branch, 'start_commit' => $startCommit,
                    'project_type' => $project->type->value, 'entry_point' => $project->type->frameworkEntryPoint(),
                    'skip_profile' => $project->type === ProjectType::Monorepo, 'php_versions' => $this->php->versions(),
                    'setup' => $setup],
                'fleet' => TaskVmReadyCopiesIntent::fleet(), 'converge' => $converge,
                'ssh_identity' => app(SwampSshExecutionInput::class)->identity(),
                'route_id' => $route?->id, 'route' => $routeInput, 'members' => $members];
            $group->taskable()->associate($instance);
            $group->fill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now(), 'capacity_wait_reason' => null])->save();

            return $this->start->execute($kind, 'task_group', $group->id, ValidatedData::integer($input['node_id']), $extend($input, $group, $instance, $vm), $caller, $request);
        }));
    }

    /**
     * "Converge once" of stage 2: the task VM role phases on the outer VM, with Incus and the nested network, run
     * before the fleet starts. The guest's host key is pinned by this run's VM step, so its SSH target carries `host_keys: null` until then.
     *
     * @param  array<string,mixed>  $input  the VM intent
     * @return array<string,mixed>|null null for a task without a topology
     */
    private function converge(TaskVm $vm, Node $node, array $input): ?array
    {
        $create = $input['create'] ?? null;
        if (! is_array($create) || ($create['topology'] ?? false) !== true) {
            return null;
        }

        return app(PendingSshHostKey::class)->during($node->id, fn (): array => app(TaskVmConvergeIntent::class)->provisioning($vm,
            ['subnet' => TaskVmReadyCopiesIntent::Subnet, 'interface' => TaskVmReadyCopiesIntent::Network]));
    }

    private function assertProvisionable(Task $group): void
    {
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
        if ($group->status === TaskGroupStatus::Backlog) {
            throw new ResourceOperationException('tasks.not_todo', 'Provision requires a todo task group.', 409);
        }
        if ($group->status !== TaskGroupStatus::Todo || $group->taskable_id !== null) {
            throw TaskGroupGuard::alreadyClaimed();
        }
    }
}
