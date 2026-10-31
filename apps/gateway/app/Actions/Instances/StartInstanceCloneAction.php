<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\CloneInstanceData;
use App\Domain\Instances\CloneCandidateSource;
use App\Domain\Instances\InstanceCloneCandidateInspector;
use App\Domain\Instances\InstanceLifecycleAvailability;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Records a production clone intent. Candidate inspection is a read; Swamp executes the clone. */
final readonly class StartInstanceCloneAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private InstanceCloneCandidateInspector $candidates,
        private RouteStateResolver $routeState,
    ) {}

    public function execute(Instance $candidate, CloneInstanceData $data, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.clone')) !== null) {
            return $replay;
        }
        app(InstanceLifecycleAvailability::class)->assertAvailable('instance.clone');

        return DB::transaction(function () use ($candidate, $data, $caller, $requestId): Operation {
            $candidate->loadMissing(['project', 'node']);
            $target = $this->existingTarget($candidate, $data);
            $created = ! $target instanceof Instance;
            $node = Node::query()->findOrFail($data->nodeId);
            $completed = $target?->clone_completed_at !== null;
            $branch = $completed ? (string) $target->branch : $data->branch ?? $this->candidateBranch($candidate);
            $source = $completed ? null : $this->candidates->inspect($candidate, $branch);
            if (! $target instanceof Instance) {
                if ($source === null) {
                    throw $this->conflict('instance.clone_candidate_unavailable', 'The candidate source is unavailable.');
                }
                [$domain, $placement] = $this->preflight($candidate, $node, $data);
                $target = $this->reserve($candidate, $node, $data, $source, $domain, $placement);
            }

            $sourceMetadata = $source === null
                ? ['candidate_node_id' => $candidate->node_id, 'candidate_path' => $candidate->checkout_path,
                    'candidate_user' => $candidate->node->user, 'candidate_environment' => $candidate->defaultAppEnv(),
                    'candidate_branch' => $candidate->branch]
                : ['candidate_node_id' => $source->node->id, 'candidate_path' => $source->basePath,
                    'candidate_user' => $source->executionUser, 'candidate_environment' => $source->environment,
                    'candidate_branch' => $source->branch];

            return $this->operations->execute('instance.clone', 'instance', $target->id, $node->id, [
                'instance_id' => $target->id, 'project_id' => $candidate->project_id, 'created' => $created,
                'candidate_id' => $candidate->id, ...$sourceMetadata,
                'candidate_commit' => $source->commit ?? $target->clone_candidate_commit,
                'initial_provisioning_step' => $target->provisioning_step,
                'selected_php_version' => $target->selected_php_version, 'source_is_laravel' => $target->source_is_laravel,
                'repository' => $candidate->project->repository_url, 'repository_identity' => $candidate->project->repository_identity,
                'branch' => $target->branch, 'root' => $target->root, 'checkout' => $target->checkout_path,
                'preview_name' => $data->previewName, 'preview_domain' => $target->clone_preview_domain,
                'sqlite_source_path' => $data->sqliteSourcePath,
                'production_user' => $target->production_user, 'production_home' => $target->production_home,
                'route_id' => $target->routes()->whereNull('web_root')->value('routes.id'),
                'initial_completed' => $target->clone_completed_at !== null,
            ], $caller, $requestId);
        });
    }

    private function existingTarget(Instance $candidate, CloneInstanceData $data): ?Instance
    {
        $existing = Instance::query()
            ->where('project_id', $candidate->project_id)
            ->where('name', $data->name)
            ->first();

        if (! $existing instanceof Instance) {
            return null;
        }

        if (
            $existing->clone_candidate_id !== $candidate->id
            || $existing->node_id !== $data->nodeId
            || ! $existing->placedOnAppProd()
            || $existing->clone_preview_name !== $data->previewName
            || $existing->clone_requested_branch !== $data->branch
            || $existing->clone_sqlite_source_path !== $data->sqliteSourcePath
        ) {
            throw $this->conflict(
                'instance.clone_retry_conflict',
                'The target Instance already exists with different immutable clone input.',
            );
        }

        if ($existing->status === InstanceState::Removing) {
            throw $this->conflict('instance.removal_conflict', 'The target Instance is being removed.');
        }

        return $existing;
    }

    private function candidateBranch(Instance $candidate): string
    {
        $branch = $candidate->placedOnAppProd()
            ? $candidate->deployment_branch ?? $candidate->branch
            : $candidate->branch;

        if (! is_string($branch) || $branch === '') {
            throw $this->conflict(
                'instance.clone_candidate_branch_invalid',
                'The candidate has no configured branch to inherit.',
            );
        }

        return $branch;
    }

    /** @return array{string, RoutePlacement} */
    private function preflight(Instance $candidate, Node $node, CloneInstanceData $data): array
    {
        $candidate->refresh()->loadMissing(['project', 'node']);
        $node->refresh();
        $placement = $this->assertPlacement($candidate, $node);

        if ($candidate->requiresRoute() && (! is_string($node->tld) || $node->tld === '')) {
            throw $this->conflict('route.tld_required', 'A clone preview requires the destination Node TLD.');
        }

        $domain = $candidate->requiresRoute()
            ? RouteDomain::validate("{$data->previewName}.{$node->tld}")
            : '';

        if ($domain !== '' && Route::query()->where('domain', $domain)->exists()) {
            throw $this->conflict('route.domain_conflict', "Route domain [{$domain}] is already owned.");
        }

        if (Instance::query()->where('project_id', $candidate->project_id)->where('name', $data->name)->exists()) {
            throw $this->conflict('instance.placement_conflict', 'The target Instance name is already owned.');
        }

        return [$domain, $placement];
    }

    private function assertPlacement(Instance $candidate, Node $node): RoutePlacement
    {
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw $this->conflict('instance.node_inactive', 'The selected app-prod Node is not active.');
        }

        if (! $node->roles()->where('role', RoleName::AppProd)->where('status', LifecycleStatus::Active)->exists()) {
            throw $this->conflict('instance.node_not_app_prod', 'The selected Node has no active app-prod role.');
        }

        $placement = $this->routeState->forNode($node);

        if ($placement->clusterId !== null) {
            $this->routeState->assertRouter($placement->clusterId);
        }

        if (Instance::query()
            ->where('project_id', $candidate->project_id)
            ->whereHas('node.roles', static fn ($query) => $query
                ->where('role', RoleName::AppProd)
                ->where('status', LifecycleStatus::Active))
            ->where('node_id', $node->id)
            ->exists()) {
            throw $this->conflict(
                'instance.production_placement_conflict',
                'The Project already has a production Instance on the selected Node.',
            );
        }

        return $placement;
    }

    private function reserve(
        Instance $candidate,
        Node $node,
        CloneInstanceData $data,
        CloneCandidateSource $source,
        string $domain,
        RoutePlacement $placement,
    ): Instance {
        $user = "orbit-app-{$candidate->project_id}";
        $home = "/home/{$user}";

        try {
            $target = DB::transaction(function () use (
                $candidate,
                $node,
                $data,
                $source,
                $domain,
                $placement,
                $user,
                $home,
            ): Instance {
                if ($domain !== '' && Route::query()->where('domain', $domain)->lockForUpdate()->exists()) {
                    throw $this->conflict(
                        'route.domain_conflict',
                        "Route domain [{$domain}] is already owned.",
                    );
                }

                $target = Instance::query()->create([
                    'project_id' => $candidate->project_id,
                    'node_id' => $node->id,
                    'name' => $data->name,
                    'source_layout' => InstanceSourceLayout::Checkout,
                    'checkout_path' => "{$home}/releases/initial",
                    'production_user' => $user,
                    'production_home' => $home,
                    'root' => $candidate->root,
                    'branch' => $data->branch ?? $source->branch,
                    'branch_override' => $data->branch,
                    'clone_candidate_id' => $candidate->id,
                    'clone_candidate_commit' => $source->commit,
                    'clone_requested_branch' => $data->branch,
                    'clone_preview_name' => $data->previewName,
                    'clone_preview_domain' => $domain,
                    'clone_sqlite_source_path' => $data->sqliteSourcePath,
                    'provisioning_step' => 'clone-reserved',
                    'status' => InstanceState::Reserved,
                ]);

                if ($domain !== '') {
                    $route = Route::query()->create([
                        'project_id' => $candidate->project_id,
                        'node_id' => $placement->nodeId,
                        'cluster_id' => $placement->clusterId,
                        'generation_basis_node_id' => null,
                        'domain' => $domain,
                        'provenance' => RouteProvenance::Explicit,
                        'publication' => RoutePublication::Private,
                        'status' => RouteStatus::Pending,
                    ]);
                    $route->targets()->create([
                        'instance_id' => $target->id,
                        'position' => 0,
                    ]);
                }

                return $target;
            });
        } catch (QueryException $exception) {
            throw new ResourceOperationException(
                errorCode: 'instance.clone_reservation_conflict',
                message: 'The target Instance or preview Route is already owned.',
                status: 409,
                previous: $exception,
            );
        }

        return $target;
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
