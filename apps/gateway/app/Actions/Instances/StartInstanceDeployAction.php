<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Actions\Projects\CaptureProjectSourceFactsAction;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\DevelopmentDeployInput;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Instances\Deployment\InstanceRuntimeDefinitions;
use App\Domain\Instances\Deployment\ProductionDeployInput;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentSync;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\NestedFleetClubKey;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Starts the `instance.deploy` operation (docs/cli/instance/deploy.mmd). It refuses an Instance that cannot be deployed
 * before it records anything; Swamp then executes the recorded intent. A production deploy holds the Instance, freezes
 * its Process and Schedule definitions, `.env` plan and PHP service, and records the running deployment, which pauses
 * automatic deployment at once. A `$commit` pins the deployment to that commit of the branch; without one it deploys
 * the branch's newest commit.
 */
final readonly class StartInstanceDeployAction
{
    public function __construct(
        private InstanceDeploymentIntent $intent,
        private StartOperationAction $operations,
        private AutomaticDeployPolicyWriter $writer,
        private InstanceDeploymentRecorder $history,
    ) {}

    public function execute(Instance $instance, string $triggeredBy, ?string $requestId, ?string $commit = null, ?int $callerNodeId = null): Operation
    {
        return $this->start($instance, $triggeredBy, $requestId, $commit, $callerNodeId);
    }

    private function start(Instance $instance, string $triggeredBy, ?string $requestId, ?string $commit, ?int $callerNodeId, bool $automatic = false): Operation
    {
        if ($commit !== null) {
            GitCommitSha::validate($commit);
        }
        $instance = Instance::query()->with(['node.roles', 'project'])->findOrFail($instance->id);
        if ($automatic) {
            $this->writer->assertSwamp($instance);
            $this->assertRepositoryIdentity($instance);
        }
        $configuration = $read = null;
        if ($instance->runtime()->exists()) {
            if (($existing = app(OperationIdempotency::class)->replay(OperationKinds::InstanceDeploy)) !== null) {
                return $existing;
            }
            $this->intent->assertDevelopment($instance);
            InstanceMigration::assertSettled($instance);
            $configuration = $this->sourceConfiguration($instance);
            $project = $instance->project;
            $read = app(CaptureProjectSourceFactsAction::class)->read($project->repository_url, $project->source_access,
                (string) $project->default_branch, $project->root, $project->type, $commit);
        }

        return DB::transaction(function () use ($instance, $triggeredBy, $requestId, $commit, $callerNodeId, $automatic, $configuration, $read): Operation {
            $instance = Instance::query()->with(['node.roles', 'project'])->lockForUpdate()->findOrFail($instance->id);
            if ($automatic) {
                $this->writer->assertSwamp($instance);
            }
            $container = $instance->runtime()->exists();
            if ($container || $configuration !== null) {
                if (($existing = app(OperationIdempotency::class)->replay(OperationKinds::InstanceDeploy)) !== null) {
                    return $existing;
                }
                if (! $container || $read === null || $configuration !== $this->sourceConfiguration($instance)) {
                    throw new ResourceOperationException('deployment.source_changed', 'The source configuration changed during deployment source capture.', 409);
                }
            }
            if ($automatic) {
                $this->assertRepositoryIdentity($instance);
            }
            // A checkout default serializes its deployments with the checkout lock on the Node; a container default holds the Instance.
            $development = $instance->placedOnAppDev();
            $members = $development ? null : app(InstanceRuntimeDefinitions::class)->capture($instance);
            if ($container) {
                $this->intent->assertDevelopment($instance);
                $facts = app(CaptureProjectSourceFactsAction::class);
                $project = $instance->project;
                if ($read['facts']['source_profile_error'] !== null) {
                    throw new ResourceOperationException($read['facts']['source_profile_error'], 'The deployed source metadata is invalid or unsupported for a development Instance.', 422);
                }
                $facts->record($project, $read);
                app(InstanceContainer::class)->assertPhp($project);
                $commit = $read['head'];
                // Render the runtime definitions only after capturing the deployed commit's facts.
                app(InstantiateProjectRuntimeDefinitionsAction::class)->refreshForContainer($instance);
            }
            $deployment = $this->intent->snapshot($instance);
            if ($development && $deployment['projection_route_id'] !== null) {
                throw new ResourceOperationException('deployment.projection_pending', 'The default Route projection must finish before deployment.', 409);
            }
            $steps = $deployment['steps'];
            if (! is_array($steps)) {
                throw new \LogicException('The deployment snapshot lists its steps.');
            }
            // The run's budget: the deploy step timeouts plus 900 s (docs/reference/deployments.md#deploy).
            $input = ['instance_id' => $instance->id, 'deployment' => $deployment,
                'node_id' => $instance->node_id, 'platform' => $instance->node->platform, 'architecture' => $instance->node->architecture,
                'run_budget_seconds' => 900 + array_sum(array_map(static fn (mixed $step): int => ValidatedData::integer(ValidatedData::object($step)['timeout_seconds']), $steps)),
                ...($commit === null ? [] : ['commit' => $commit])];
            if ($development) {
                $input['development_runtime'] = app(DevelopmentDeployInput::class)->capture($instance, $deployment['needs_vp'] === true);
                $input['github'] = ['$secret' => 'github.installation', 'id' => $instance->node_id];
            } else {
                $input['runtime_definitions'] = $members;
                $input['direct_environment'] = app(NativeInstanceEnvironmentSync::class)->plan(
                    app(InstanceEnvironmentContextResolver::class)->resolve($instance, requireActiveNode: true, lockRoute: true), includeRuntime: false);
                $input['php_input'] = app(ProductionDeployInput::class)->phpInput($instance, $deployment);
                $input['vp_setup'] = $deployment['needs_vp'] === true ? app(ToolManagerIntent::class)->nativeInput($instance->node, ToolManagerName::Vp) : null;
            }
            $admitted = $deployment['container'] ?? null;
            if (is_array($admitted) && isset($admitted['club_key'])) {
                // Whether this nested-fleet start gets its Club key; without it the container starts without one.
                $input['nested_fleet_club_key'] = NestedFleetClubKey::status();
            }
            if ($callerNodeId !== null) {
                $input['caller_node_id'] = $callerNodeId;
            }
            $operation = $this->operations->execute(OperationKinds::InstanceDeploy, OperationKinds::InstanceTarget, $instance->id, $instance->node_id,
                $input, $triggeredBy, $requestId, lockKey: $development && $deployment['variant'] !== 'container' ? null : OperationStore::lockKey(OperationKinds::InstanceTarget, $instance->id));
            if ($development || ! $operation->wasRecentlyCreated) {
                return $operation;
            }
            // The running row pauses automatic deployment now, not when the run ends.
            $row = $this->history->start($instance, $triggeredBy, callerNodeId: $callerNodeId);
            $operation->forceFill(['state' => [...($operation->state ?? []), 'deployment_id' => $row->id]])->save();

            return $operation;
        });
    }

    /** Public pins validate the recorded Project identity before admitting the existing operation. */
    public function pinned(Instance $instance, string $triggeredBy, ?string $requestId, string $commit, ?int $callerNodeId = null): Operation
    {
        $this->assertRepositoryIdentity($instance);

        return $this->execute($instance, $triggeredBy, $requestId, $commit, $callerNodeId);
    }

    /** The HTTP boundary requires Gateway Node access before admitting automatic origin. */
    public function automatic(Instance $instance, Node $caller, ?string $requestId, string $commit): Operation
    {
        return $this->start($instance, AutomaticDeployHistory::SwampTrigger, $requestId, $commit, $caller->id, automatic: true);
    }

    private function assertRepositoryIdentity(Instance $instance): void
    {
        $project = $instance->project()->firstOrFail();
        if ($project->repository_identity !== GitRepositoryIdentity::derive($project->repository_url)) {
            throw new ResourceOperationException('deployment.repository_mismatch', 'The Project repository identity does not match its origin.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function sourceConfiguration(Instance $instance): array
    {
        return ['instance' => $instance->only(['project_id', 'node_id', 'root']), 'runtime_id' => $instance->runtime()->value('id'),
            'project' => $instance->project->only(['repository_url', 'repository_identity', 'source_access', 'default_branch', 'root', 'type'])];
    }
}
