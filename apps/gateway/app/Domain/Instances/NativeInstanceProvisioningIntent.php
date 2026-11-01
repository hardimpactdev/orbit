<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Nodes\MachineArchitecture;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** Immutable parent admission. No child Operations and no Node effects. */
final readonly class NativeInstanceProvisioningIntent
{
    public function assertCreateSupported(Project $project, Node $node, bool $database): void
    {
        if ($database || $project->type->isWebServing() || TaskVm::forNode($node) !== null
            || $project->processDefinitions()->exists() || $project->scheduleDefinitions()->exists()) {
            $this->unavailable();
        }
    }

    public function assertCloneSupported(Instance $candidate, Node $node, ?string $sqliteSource): void
    {
        if ($candidate->requiresRoute() || $sqliteSource !== null || TaskVm::forNode($node) !== null
            || TaskVm::forNode($candidate->node) !== null || $candidate->databaseConnectionTargets()->exists()
            || $candidate->project->processDefinitions()->exists() || $candidate->project->scheduleDefinitions()->exists()) {
            $this->unavailable();
        }
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(Instance $instance, array $input): array
    {
        $instance->loadMissing(['node', 'project']);
        $this->assertCreateSupported($instance->project, $instance->node, false);
        if ($instance->routes()->exists() || $instance->requiresRoute() || $instance->databaseConnectionTargets()->exists()) {
            $this->unavailable();
        }
        if (($input['user'] ?? null) !== $instance->node->user) {
            $this->unavailable();
        }
        $plan = $this->common($instance, 'development', (string) $input['user']);

        return [...$plan, 'source' => [
            'repository' => $input['repository'], 'checkout' => $instance->checkout_path,
            'root' => $input['source_root'], 'user' => $input['user'],
            'prepare_id' => $input['prepare_id'], 'seed_repository' => $input['seed_repository'],
        ], 'resolve' => ['instance_name' => $instance->name, 'default_branch' => $input['default_branch'],
            'branch_override' => $input['branch_override'], 'seed_commit' => $input['seed_commit'],
            'network' => true, 'protocols' => ValidatedData::stringList(config('orbit.git.checkout_protocols', ['https']))],
            'setup' => ['instance_id' => $instance->id, 'list' => 'setup',
                ...app(StartInstanceStepListAction::class)->appStepAdmission($instance, LifecyclePhase::Setup)],
            'already_active' => $instance->status === InstanceState::Active && ! $instance->first_setup_pending,
            'retained_source' => in_array($instance->status, [InstanceState::Active, InstanceState::SourceResolved], true) && is_bool($instance->source_is_laravel) ? ['branch' => $instance->branch,
                'commit' => $instance->starting_commit, 'php_version' => $instance->selected_php_version,
                'laravel' => $instance->source_is_laravel] : null];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function clone(Instance $instance, array $input): array
    {
        $instance->loadMissing(['node', 'project']);
        $user = $instance->node->user;
        if (! $instance->placedOnAppDev() || $instance->routes()->exists()) {
            $this->unavailable();
        }

        return [...$this->common($instance, 'development', $user),
            'vp_setup' => app(ToolManagerIntent::class)->nativeInput($instance->node, ToolManagerName::Vp),
            'source' => ['repository' => $input['repository'], 'checkout' => $instance->checkout_path,
                'root' => $input['source_root'], 'user' => $user, 'seed_repository' => null, 'prepare_id' => $instance->source_prepare_id],
            'resolve' => ['instance_name' => $instance->name, 'default_branch' => $instance->project->default_branch,
                'branch_override' => $input['branch'], 'require_branch' => true, 'seed_commit' => null, 'network' => true],
            'candidate' => $input['candidate'], 'already_active' => $instance->clone_completed_at !== null,
            'retained_source' => $instance->clone_completed_at !== null ? ['branch' => $instance->branch,
                'commit' => $instance->starting_commit, 'php_version' => $instance->selected_php_version,
                'laravel' => $instance->source_is_laravel] : null];
    }

    /** @return array<string,mixed> */
    private function common(Instance $instance, string $environment, string $user): array
    {
        if ($instance->node->user === 'root' || preg_match('/\A[a-z_][a-z0-9_-]{0,63}\z/D', $instance->node->user) !== 1) {
            $this->unavailable();
        }

        return ['instance_id' => $instance->id, 'project_id' => $instance->project_id,
            'node_id' => $instance->node_id, 'node_user' => $instance->node->user,
            'platform' => $instance->node->platform,
            'architecture' => MachineArchitecture::normalize($instance->node->architecture) ?? $this->unavailable(),
            'vp_materialize' => app(ToolManagerIntent::class)->input($instance->node, ToolManagerName::Vp)['materialize'] ?? [],
            'environment' => $environment, 'user' => $user, 'checkout' => $instance->checkout_path,
            'application' => ApplicationDirectory::resolve($instance->checkout_path, $instance->root ?? $instance->project->root),
            'project_type' => $instance->project->type->value,
            'entry_point' => $instance->project->type->frameworkEntryPoint(),
            'skip_profile' => $instance->project->type === ProjectType::Monorepo,
            'php_versions' => app(InstancePhpVersionCatalog::class)->versions()];
    }

    private function unavailable(): never
    {
        throw new ResourceOperationException('operation.kind_unavailable', 'This Instance needs native publication, database, VM or runtime steps that are not available for this parent yet.', 503);
    }
}
