<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeInstanceProvisioningIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Validates development placement and records intent. Does no Node mutation. */
final readonly class StartDevelopmentInstanceCreateAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $overlap,
        private InstanceDatabaseClonePlanner $databases,
        private DevelopmentNodeExclusion $exclusions,
    ) {}

    public function execute(CreateInstanceData $data, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = $this->idempotency->replay('instance.create')) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($data, $caller, $requestId): Operation {
            $project = Project::query()->findOrFail($data->projectId);
            if ($project->source_access === ProjectSourceAccess::GhCli) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Native creation requires anonymous or scoped GitHub App repository access.', 503);
            }
            $node = Node::query()->findOrFail($data->nodeId);
            $this->assertDefaults($project);
            $this->assertPlacement($node);
            $root = $data->root === null ? null : ProjectRoot::validate($data->root, $project->type);
            $name = DefaultInstanceName::resolve($project, $data->name);
            $instance = Instance::query()->where('project_id', $project->id)->where('name', $name)->lockForUpdate()->first();
            $created = ! $instance instanceof Instance;

            if ($instance instanceof Instance) {
                $this->assertRetry($instance, $node, $root, $data->branch);
            }
            $clone = $instance instanceof Instance && ! $instance->first_setup_pending
                ? null : $this->databases->plan($project, $name);
            app(NativeInstanceProvisioningIntent::class)->assertCreateSupported($project, $node, $data->databaseServer !== null || $clone !== null);
            $sourceRoot = $this->nodeSettings->fromStored($node->settings)?->appsPath();
            if (! is_string($sourceRoot) || StoragePath::tryParse($sourceRoot) === null || $data->domain !== null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Native creation requires an explicit apps.path and no Route.', 503);
            }
            if ($instance instanceof Instance && $instance->status === InstanceState::Active
                && ($instance->failed_step === 'setup' || $instance->first_setup_pending)
            ) {
                throw new ResourceOperationException('instance.setup_step_failed', 'Setup is incomplete. Run instance:setup before using this Instance.', 409);
            }
            if (! $instance instanceof Instance) {
                $this->exclusions->assertAvailable($project, $node);
                $checkout = StoragePath::parse($sourceRoot)->append($project->slug, $name);
                $this->overlap->assertAvailable($node->id, $checkout,
                    $name === 'default' ? 'instance.default_path_occupied' : 'instance.path_taken');
                $instance = Instance::query()->create([
                    'project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
                    'source_layout' => InstanceSourceLayout::Checkout, 'checkout_path' => $checkout->value,
                    'source_prepare_id' => (string) Str::uuid(), 'root' => $root, 'branch_override' => $data->branch,
                    'status' => InstanceState::Reserved, 'first_setup_pending' => true,
                ]);
            }

            $instance = app(SelectInstanceSeedAction::class)->execute($instance);
            $lists = app(StartInstanceStepListAction::class);
            $digest = static fn (LifecyclePhase $phase): string => $lists->configurationDigest($project, $phase);

            $input = [
                'instance_id' => $instance->id, 'project_id' => $project->id, 'created' => $created,
                'name' => $name, 'name_converted_from' => DefaultInstanceName::convertedFrom($project, $data->name),
                'root' => $root, 'domain' => $data->domain, 'branch_override' => $data->branch,
                'checkout' => $instance->checkout_path, 'prepare_id' => $instance->source_prepare_id,
                'repository' => $project->repository_url, 'repository_identity' => $project->repository_identity,
                'default_branch' => $project->default_branch,
                'task_vm' => false,
                'node_id' => $node->id, 'initial_status' => $instance->status->value, 'first_setup_pending' => $instance->first_setup_pending,
                'user' => $node->user, 'source_root' => $sourceRoot,
                'participant_node_ids' => [$node->id],
                'seed_repository' => $instance->seed_repository, 'seed_commit' => $instance->seed_commit, 'seed_path' => $instance->seed_path,
                'source_layout' => $instance->source_layout,
                'route_id' => null, 'route_domain' => null,
                'setup_digest' => $digest(LifecyclePhase::Setup), 'teardown_digest' => $digest(LifecyclePhase::Teardown),
            ];
            $input['ssh_execution'] = app(SwampSshExecutionInput::class)->forNode($node);
            $input['direct_provisioning'] = app(NativeInstanceProvisioningIntent::class)->create($instance, $input);

            return $this->operations->execute('instance.create', 'instance', $instance->id, $node->id, $input, $caller, $requestId);
        });
    }

    private function assertDefaults(Project $project): void
    {
        if (! is_string($project->default_branch) || ! GitBranchName::isValid($project->default_branch)
            || ! is_string($project->root) || ! ProjectRoot::isValid($project->root, $project->type)) {
            throw new ResourceOperationException('project.source_defaults_incomplete', "App [{$project->slug}] does not have complete source defaults.");
        }
        GitRepositoryOrigin::validate($project->repository_url);
    }

    private function assertPlacement(Node $node): void
    {
        if ($node->roles()->where('role', RoleName::AppProd)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('instance.candidate_required', 'Production Instances require instance:clone.', 409);
        }
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw new ResourceOperationException('instance.node_inactive', 'The selected app-dev Node is not active.');
        }
        if (! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('instance.node_not_app_dev', 'The selected Node has no active app-dev role.');
        }
    }

    private function assertRetry(Instance $instance, Node $node, ?string $root, ?string $branch): void
    {
        if ($instance->status === InstanceState::Removing) {
            throw new ResourceOperationException('instance.removal_conflict', "Instance [{$instance->name}] is being removed.", 409);
        }
        if ($instance->node_id !== $node->id || $instance->root !== $root || $instance->branch_override !== $branch
            || ! ($instance->source_layout === InstanceSourceLayout::Checkout->value
                || $instance->source_layout === InstanceSourceLayout::Worktree->value && $instance->seed_repository !== null)) {
            throw new ResourceOperationException('instance.placement_conflict', 'Instance placement is immutable.', 409);
        }
    }
}
