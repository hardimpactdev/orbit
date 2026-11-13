<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\CloneInstanceData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\Environment\CloneEnvironmentWarnings;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceSourceProfileGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeInstanceProvisioningIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\ScheduleInstanceOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Captures production clone intent; source execution belongs to one native 209 root. */
final readonly class StartInstanceCloneAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency) {}

    public function execute(Instance $candidate, CloneInstanceData $data, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.clone')) !== null) {
            return $replay;
        }
        InstancePlacement::assert($candidate->project);
        InstanceContainer::assertCheckout($candidate);

        return DB::transaction(function () use ($candidate, $data, $caller, $requestId): Operation {
            $candidate->refresh()->loadMissing(['project', 'node']);
            $node = Node::query()->findOrFail($data->nodeId);
            app(NativeInstanceProvisioningIntent::class)->assertCloneSupported($candidate, $node, $data->sqliteSourcePath);
            if ($candidate->status !== InstanceState::Active || $candidate->provisioning_step !== 'active'
                || $candidate->node->status !== LifecycleStatus::Active || $candidate->node->platform !== 'linux') {
                throw $this->conflict('instance.clone_candidate_inactive', 'The candidate must be active.');
            }
            if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
                throw $this->conflict('instance.node_inactive', 'The selected app-prod Node is not active.');
            }
            if (! $node->roles()->where('role', RoleName::AppProd)->where('status', LifecycleStatus::Active)->exists()) {
                throw $this->conflict('instance.node_not_app_prod', 'The selected Node has no active app-prod role.');
            }
            if (! is_bool($candidate->source_is_laravel)) {
                new InstanceSourceProfileGuard()->refuseMissing();
            }
            $branch = $data->branch ?? $candidate->branch;
            if (! is_string($branch) || ! GitBranchName::isValid($branch)
                || ! is_string($candidate->branch) || ! GitBranchName::isValid($candidate->branch)) {
                throw $this->conflict('instance.clone_candidate_branch_invalid', 'The candidate has no configured branch to inherit.');
            }
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $data->name) !== 1) {
                throw $this->conflict('instance.name_invalid', 'The Instance name must be a lowercase DNS label.');
            }
            // One production account and home per Project per Node; deploy refuses any other.
            $user = 'orbit-app-'.$candidate->project_id;
            $home = '/home/'.$user;
            $path = $home.'/releases/initial';
            $target = Instance::query()->where('project_id', $candidate->project_id)->where('name', $data->name)->lockForUpdate()->first();
            if ($target !== null && ($target->clone_candidate_id !== $candidate->id || $target->node_id !== $node->id
                || $target->checkout_path !== $path || $target->clone_preview_name !== $data->previewName
                || $target->clone_requested_branch !== $data->branch || $target->clone_sqlite_source_path !== $data->sqliteSourcePath)) {
                throw $this->conflict('instance.clone_retry_conflict', 'The target Instance already exists with different immutable clone input.');
            }
            if ($target?->status === InstanceState::Removing) {
                throw $this->conflict('instance.removal_conflict', 'The target Instance is being removed.');
            }
            if ($target?->clone_completed_at !== null) {
                return Operation::query()->where('kind', 'instance.clone')->where('target_type', 'instance')->where('target_id', $target->id)
                    ->where('status', 'succeeded')->orderByDesc('id')->first()
                    ?? throw $this->conflict('instance.clone_retry_conflict', 'The completed clone has no successful admission receipt.');
            }
            if ($target === null && Instance::query()->where('project_id', $candidate->project_id)->where('node_id', $node->id)
                ->where('production_home', $home)->exists()) {
                throw $this->conflict('instance.production_placement_conflict', 'The Project already has a production Instance on the selected Node.');
            }
            $target ??= Instance::query()->create(['project_id' => $candidate->project_id, 'node_id' => $node->id,
                'name' => $data->name, 'checkout_path' => $path, 'source_layout' => 'checkout', 'root' => $candidate->root,
                'production_user' => $user, 'production_home' => $home,
                'branch' => $branch, 'branch_override' => $data->branch, 'status' => InstanceState::Reserved,
                'source_is_laravel' => $candidate->source_is_laravel,
                'clone_candidate_id' => $candidate->id, 'clone_requested_branch' => $data->branch,
                'clone_preview_name' => $data->previewName, 'clone_preview_domain' => '',
                'source_prepare_id' => (string) Str::uuid(), 'clone_sqlite_source_path' => $data->sqliteSourcePath, 'provisioning_step' => 'clone-reserved']);
            if ($target->clone_completed_at === null) {
                $contexts = app(InstanceEnvironmentContextResolver::class);
                app(InstanceEnvironmentStore::class)->copyForClone($contexts->resolve($candidate, true), $contexts->resolveForClone($target, true));
                app(InstanceEnvironmentStore::class)->forceAppProdMode($target);
            }
            $sqlite = $data->sqliteSourcePath === null ? null : $this->sqlite($candidate, $target, $data->sqliteSourcePath);
            $route = $candidate->requiresRoute() ? $this->previewRoute($target, $node, $data->previewName) : null;
            $input = ['instance_id' => $target->id, 'project_id' => $candidate->project_id, 'candidate_id' => $candidate->id,
                'repository' => $candidate->project->repository_url, 'repository_identity' => $candidate->project->repository_identity,
                'branch' => $branch, 'checkout' => $path, 'candidate' => ['node_id' => $candidate->node_id,
                    'checkout' => $candidate->checkout_path, 'branch' => $candidate->branch,
                    'repository_identity' => $candidate->project->repository_identity],
                'initial_completed' => $target->clone_completed_at !== null, 'sqlite' => $sqlite,
                // Key-only hints for copied development references; values never enter the input.
                'warnings' => app(CloneEnvironmentWarnings::class)->detect(app(InstanceEnvironmentStore::class)->values($target), $candidate->node, $home, $node)];
            if ($route !== null) {
                $input = [...$input, ...$this->previewPlan($route, $target, $node)];
            }
            $input['direct_provisioning'] = app(NativeInstanceProvisioningIntent::class)->clone($target, $input);
            // Production Process and Schedule definitions become records now; the run installs them, enabled but not started.
            $input['members'] = app(ProcessInstanceOperation::class)->capture($target, 'process.install_instance_definitions')['members'];
            $input['schedule_members'] = app(ScheduleInstanceOperation::class)->capture($target)['members'];

            return $this->operations->execute('instance.clone', 'instance', $target->id, $node->id, $input, $caller, $requestId);
        });
    }

    /**
     * The chosen SQLite file becomes the clone's own database under the account's home, attached under DB, so the
     * rendered .env already names it; the run snapshots and installs it.
     *
     * @return array{source:string,user:string,snapshot:string,target:string}
     */
    private function sqlite(Instance $candidate, Instance $target, string $source): array
    {
        $path = $target->production_home.'/database.sqlite';
        $slug = $candidate->project->slug.'-'.$target->name;
        $slug = strlen($slug) <= 63 ? $slug : rtrim(substr($slug, 0, 54), '-').'-'.substr(sha1($slug), 0, 8);
        $connection = DatabaseConnection::query()->firstOrCreate(['slug' => $slug],
            ['driver' => DatabaseDriver::Sqlite->value, 'node_id' => $target->node_id, 'owner_instance_id' => $target->id, 'path' => $path]);
        if ($connection->owner_instance_id !== $target->id || $connection->path !== $path) {
            throw $this->conflict('instance.clone_database_slug_taken', 'The cloned database connection slug is already taken by another owner.');
        }
        app(AttachDatabaseConnectionAction::class)->project($target, $connection, 'DB', forClone: true, replace: true);
        $user = $candidate->placedOnAppProd() ? $candidate->production_user : $candidate->node->user;

        return ['source' => $source, 'user' => ValidatedData::string($user),
            'snapshot' => '/tmp/orbit-clone-sqlite-'.$target->id.'.sqlite', 'target' => $path];
    }

    /**
     * The private preview Route inside the Node's TLD. It stays pending until the clone succeeds; private DNS
     * already answers the whole TLD, so the run publishes no DNS.
     */
    private function previewRoute(Instance $target, Node $node, string $previewName): Route
    {
        if (! is_string($node->tld) || $node->tld === '') {
            throw $this->conflict('route.tld_required', 'A clone preview requires the destination Node TLD.');
        }
        // A Cluster Route goes through its Router; the clone publishes only on its own Node.
        if (app(RouteStateResolver::class)->forNode($node)->clusterId !== null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'A clone preview through a Cluster Router is not available.', 503);
        }
        $domain = RouteDomain::validate($previewName.'.'.$node->tld);
        $route = $target->routes()->first();
        if ($route === null) {
            if ($target->clone_preview_domain !== '' || Route::query()->where('domain', $domain)->lockForUpdate()->exists()) {
                throw $this->conflict('route.domain_conflict', "Route domain [{$domain}] is already owned.");
            }
            $route = Route::query()->create(['project_id' => $target->project_id, 'node_id' => $node->id, 'domain' => $domain,
                'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
            $route->targets()->create(['instance_id' => $target->id, 'position' => 0]);
            $target->update(['clone_preview_domain' => $domain]);
        }
        if ($route->domain !== $domain || $route->status !== RouteStatus::Pending) {
            throw $this->conflict('instance.clone_retry_conflict', 'The target Instance already has another preview Route.');
        }

        return $route;
    }

    /**
     * The route.create plan the run's CSR, Sign and Publish steps take, captured after the copied environment, so the
     * Route's .env carries the same values. Only a same-Node private site is in scope.
     *
     * @return array<string,mixed>
     */
    private function previewPlan(Route $route, Instance $target, Node $node): array
    {
        $capture = app(NativeRouteAdmission::class)->capture('route.create', $route->refresh(),
            ['mode' => 'converge', 'created' => true, 'instance_id' => $target->id], $node->id);
        $plan = ValidatedData::object($capture['route_create']);
        if ($plan['lan'] !== [] || $plan['ingress_ports'] !== [] || $plan['ingress'] !== [] || $plan['dns'] !== []
            || ValidatedData::integerList($plan['site_nodes']) !== [$node->id]) {
            throw $this->conflict('operation.kind_unavailable', 'The preview Route needs more than the clone Node.');
        }

        return ['route_id' => $route->id, 'route_lifecycle' => $capture['route_lifecycle'], 'route_create' => $plan,
            'certificates' => $capture['certificates'], 'route_execution_secrets' => $capture['execution_secrets']];
    }

    private function conflict(string $code, string $message): ResourceOperationException
    {
        return new ResourceOperationException($code, $message, 409);
    }
}
