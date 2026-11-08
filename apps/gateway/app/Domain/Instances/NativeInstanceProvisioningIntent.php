<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Nodes\MachineArchitecture;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** Immutable parent admission. No child Operations and no Node effects. */
final readonly class NativeInstanceProvisioningIntent
{
    public function assertCreateSupported(Node $node): void
    {
        if (TaskVm::forNode($node) !== null) {
            throw new ResourceOperationException('instance.task_vm_node', 'A task VM gets its workspace from task:start, not instance:create.', 409);
        }
    }

    public function assertCloneSupported(Instance $candidate, Node $node, ?string $sqliteSource): void
    {
        if (TaskVm::forNode($node) !== null
            || TaskVm::forNode($candidate->node) !== null || $candidate->databaseConnectionTargets()->exists()) {
            $this->unavailable();
        }
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(Instance $instance, array $input): array
    {
        $instance->loadMissing(['node', 'project']);
        $this->assertCreateSupported($instance->node);
        // An already active Instance runs no phase; otherwise only the database copy this create owns may be attached.
        $alreadyActive = $instance->status === InstanceState::Active && ! $instance->first_setup_pending;
        if (! $alreadyActive && $instance->databaseConnectionTargets()
            ->whereHas('databaseConnection', static fn ($query) => $query->where('owner_instance_id', '!=', $instance->id)->orWhereNull('owner_instance_id'))->exists()) {
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
        ], 'resolve' => ['default_branch' => $input['default_branch'],
            'branch' => $input['branch_override'] ?? ($instance->name === 'default' ? $input['default_branch'] : $instance->name),
            'seed_commit' => $input['seed_commit'],
            'network' => true, 'protocols' => ValidatedData::stringList(config('orbit.git.checkout_protocols', ['https']))],
            'setup' => ['instance_id' => $instance->id, 'list' => 'setup',
                ...app(StartInstanceStepListAction::class)->appStepAdmission($instance, LifecyclePhase::Setup)],
            'already_active' => $alreadyActive,
            'retained_source' => in_array($instance->status, [InstanceState::Active, InstanceState::SourceResolved], true) && is_string($instance->starting_commit)
                ? ['commit' => $instance->starting_commit] : null];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function clone(Instance $instance, array $input): array
    {
        $instance->loadMissing(['node', 'project']);
        $user = $instance->production_user;
        $home = $instance->production_home;
        // Only the clone's own pending preview Route may target it.
        if (! $instance->placedOnAppProd() || $instance->routes()->where(fn ($query) => $query->where('routes.status', '!=', RouteStatus::Pending)
            ->orWhere('routes.domain', '!=', $instance->clone_preview_domain))->exists() || ! is_string($user) || ! is_string($home)
            || $user !== 'orbit-app-'.$instance->project_id || $home !== '/home/'.$user) {
            $this->unavailable();
        }

        $suffix = substr(ApplicationDirectory::resolve($instance->checkout_path, $instance->root ?? $instance->project->root), strlen(rtrim($instance->checkout_path, '/')));

        return [...$this->common($instance, 'production', $user),
            'vp_setup' => app(ToolManagerIntent::class)->nativeInput($instance->node, ToolManagerName::Vp),
            // Production deploy refuses a home without this root-owned marker (instance-deploy deploy_local.ts).
            'home' => ['path' => $home, 'state' => '/var/lib/orbit/app-instance-sources/'.$instance->id,
                'release_layout' => base64_encode($instance->project->repository_url."\0".$user."\0".$home."\0initial\0"),
                // The release's application .env links to the home .env, as a deploy's release does.
                'environment_link' => str_repeat('../', 2 + substr_count($suffix, '/')).'.env'],
            'source' => ['repository' => $input['repository'], 'checkout' => $instance->checkout_path,
                'root' => $home.'/releases', 'user' => $user, 'seed_repository' => null, 'prepare_id' => $instance->source_prepare_id],
            'resolve' => ['instance_name' => $instance->name, 'default_branch' => $instance->project->default_branch,
                'branch_override' => $input['branch'], 'require_branch' => true, 'seed_commit' => null, 'network' => true],
            'candidate' => $input['candidate'], 'sqlite' => $input['sqlite'] ?? null, 'route' => $input['route_create'] ?? null, 'already_active' => $instance->clone_completed_at !== null,
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
