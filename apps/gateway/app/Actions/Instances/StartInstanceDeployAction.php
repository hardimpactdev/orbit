<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Actions\Projects\CaptureProjectSourceFactsAction;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\ContainerDeployOutcome;
use App\Domain\Instances\Deployment\DevelopmentDeployInput;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Instances\Deployment\ProductionContainerRelease;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Instances\PhpSymbolIndex;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Domain\SourceControl\GitRepositoryIdentity;
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

    /** `$release`: the release's nested-fleet refresh admits it (NestedFleetRefresh), which the input records as its `origin`. */
    public function execute(Instance $instance, string $triggeredBy, ?string $requestId, ?string $commit = null, ?int $callerNodeId = null, bool $release = false): Operation
    {
        return $this->start($instance, $triggeredBy, $requestId, $commit, $callerNodeId, release: $release);
    }

    private function start(Instance $instance, string $triggeredBy, ?string $requestId, ?string $commit, ?int $callerNodeId, bool $automatic = false, bool $release = false): Operation
    {
        if ($commit !== null) {
            GitCommitSha::validate($commit);
        }
        $instance = Instance::query()->with(['node.roles', 'project'])->findOrFail($instance->id);
        if ($automatic) {
            $this->writer->assertSwamp($instance);
            $this->assertRepositoryIdentity($instance);
        }
        $configuration = $read = $agentFolder = null;
        if ($instance->runtime()->exists()) {
            if (($existing = app(OperationIdempotency::class)->replay(OperationKinds::InstanceDeploy)) !== null) {
                return $existing;
            }
            $this->intent->assertDevelopment($instance);
            InstanceMigration::assertSettled($instance);
            $configuration = $this->sourceConfiguration($instance);
            $project = $instance->project;
            $read = app(CaptureProjectSourceFactsAction::class)->read($project->repositoryUrl(), $project->source_access,
                (string) $project->default_branch, $project->root, $project->type, $commit);
            // The seed's agent folder: the Project's instructions are read here, before the transaction
            // (docs/reference/development-containers.md#agent-instructions).
            $agentFolder = app(InstanceContainer::class)->seedAgentFolder($project);
        }

        return DB::transaction(function () use ($instance, $triggeredBy, $requestId, $commit, $callerNodeId, $automatic, $release, $configuration, $read, $agentFolder): Operation {
            $instance = Instance::query()->with(['node.roles', 'project'])->lockForUpdate()->findOrFail($instance->id);
            if ($automatic) {
                $this->writer->assertSwamp($instance);
            }
            if (($existing = app(OperationIdempotency::class)->replay(OperationKinds::InstanceDeploy)) !== null) {
                return $existing;
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
            $deployment = $development ? $this->intent->snapshot($instance, agentFolder: $agentFolder) : [
                'variant' => 'production-release', 'project_id' => $instance->project_id,
                'steps' => array_map(static fn ($step): array => ['timeout_seconds' => $step->timeoutSeconds], app(InstanceDeploymentConfigResolver::class)->resolve($instance)->steps),
            ];
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
                'run_budget_seconds' => 900 + array_sum(array_map(static fn (mixed $step): int => ValidatedData::integer(ValidatedData::object($step)['timeout_seconds']), $steps))
                    + (isset($deployment['fleet']) ? NestedFleetIntent::RunSeconds : 0)
                    // A Laravel default on an app-dev Node prepares its PHP symbol index before its seed.
                    + PhpSymbolIndex::runSeconds($deployment['container'] ?? null)
                    // The proof default first waits for its seed hold (docs/reference/development-containers.md#the-seed-hold).
                    + (is_array($deployment['container'] ?? null) && ($deployment['container']['seed_hold'] ?? false) === true ? ProofSeeds::HoldWaitSeconds : 0),
                ...($commit === null ? [] : ['commit' => $commit])];
            if ($development) {
                $input['development_runtime'] = app(DevelopmentDeployInput::class)->capture($instance, $deployment['needs_vp'] === true);
                $input['github'] = ['$secret' => 'github.installation', 'id' => $instance->node_id];
            } else {
                $input = [...$input, ...app(ProductionContainerRelease::class)->admission($instance, $commit, automatic: $automatic)];
            }
            if ($callerNodeId !== null) {
                $input['caller_node_id'] = $callerNodeId;
            }
            // Who admitted it, from the admission itself and never from `triggered_by`, which a caller names
            // (docs/reference/deployments.md#development-defaults).
            if ($release || $automatic) {
                $input['origin'] = $release ? ContainerDeployOutcome::ReleaseOrigin : ContainerDeployOutcome::AutomaticOrigin;
            }
            $operation = $this->operations->execute(OperationKinds::InstanceDeploy, OperationKinds::InstanceTarget, $instance->id, $instance->node_id,
                $input, $triggeredBy, $requestId, lockKey: $development && $deployment['variant'] !== 'container' ? null : OperationStore::lockKey(OperationKinds::InstanceTarget, $instance->id));
            if ($development || ! $operation->wasRecentlyCreated) {
                return $operation;
            }
            if ($instance->production_release_started_at === null) {
                $instance->forceFill(['production_release_started_at' => now()])->saveOrFail();
            }
            // The running row pauses automatic deployment now, not when the run ends.
            $row = $this->history->start($instance, $triggeredBy, callerNodeId: $callerNodeId);
            $operation->forceFill(['state' => [...($operation->state ?? []), 'deployment_id' => $row->id]])->save();

            return $operation;
        });
    }

    /** Public pins validate the recorded Project identity before admitting the existing operation. */
    public function pinned(Instance $instance, string $triggeredBy, ?string $requestId, string $commit, ?int $callerNodeId = null, bool $release = false): Operation
    {
        $this->assertRepositoryIdentity($instance);

        return $this->execute($instance, $triggeredBy, $requestId, $commit, $callerNodeId, $release);
    }

    /** The HTTP boundary requires Gateway Node access before admitting automatic origin. */
    public function automatic(Instance $instance, Node $caller, ?string $requestId, string $commit): Operation
    {
        return $this->start($instance, AutomaticDeployHistory::SwampTrigger, $requestId, $commit, $caller->id, automatic: true);
    }

    private function assertRepositoryIdentity(Instance $instance): void
    {
        $project = $instance->project()->firstOrFail();
        if ($project->repository_identity !== GitRepositoryIdentity::derive($project->repositoryUrl())) {
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
