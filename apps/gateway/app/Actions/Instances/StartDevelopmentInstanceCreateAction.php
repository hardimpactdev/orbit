<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\Operations\StartOperationAction;
use App\Actions\Projects\CaptureProjectSourceFactsAction;
use App\Actions\Routes\CreateRouteAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\ContainerNodes;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlan;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\Deployment\DevelopmentDeploySteps;
use App\Domain\Instances\DevelopmentInstanceBranchAdmission;
use App\Domain\Instances\DevelopmentInstanceCreateAdmission;
use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeInstanceProvisioningIntent;
use App\Domain\Instances\OwnedDatabaseSlug;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ProjectRoot;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Support\ValidatedData;
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
        private DevelopmentInstanceCreateAdmission $admission,
    ) {}

    public function execute(CreateInstanceData $data, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = $this->idempotency->replay('instance.create')) !== null) {
            return $replay;
        }

        // Projects added before source facts existed capture them once, outside the admission transaction.
        $project = Project::query()->findOrFail($data->projectId);
        if ($project->source_facts_captured_at === null && $project->source_access !== ProjectSourceAccess::GhCli) {
            app(CaptureProjectSourceFactsAction::class)->execute($project);
        }

        $observed = $project;
        $selection = $data->branchSelection === null ? null
            : app(DevelopmentInstanceBranchAdmission::class)->prepare($project, $data->name, $data->branchSelection, $caller);

        return DB::transaction(function () use ($data, $caller, $requestId, $selection, $observed): Operation {
            $project = Project::query()->findOrFail($data->projectId);
            $this->admission->assertSourceAccess($project);
            $node = Node::query()->findOrFail($data->nodeId);
            $this->admission->assertProject($project, $node);
            $root = $data->root === null ? null : ProjectRoot::validate($data->root, $project->type);
            $name = DefaultInstanceName::resolve($project, $data->name);
            $instance = Instance::query()->where('project_id', $project->id)->where('name', $name)->lockForUpdate()->first();
            $created = ! $instance instanceof Instance;
            // A container Instance is the Project's default, or a clone of that default ($source).
            [$container, $source] = $this->container($project, $node, $name, $instance, $data);
            $this->admission->assertNestedContainer($project, $node, $container, $instance, $source);
            $selection = $selection === null ? null
                : app(DevelopmentInstanceBranchAdmission::class)->reserve($project, $observed, $selection, $instance, $caller);

            if ($instance instanceof Instance) {
                $this->assertRetry($instance, $node, $root, $data->branch);
                if ($data->databaseServer !== null && $instance->status === InstanceState::Active && ! $instance->first_setup_pending
                    && ! DatabaseConnection::query()->where('owner_instance_id', $instance->id)
                        ->whereHas('server', static fn ($query) => $query->where('slug', $data->databaseServer))->exists()) {
                    throw new ResourceOperationException('instance.database_server_existing',
                        "Instance [{$instance->name}] finished its first setup. Create its database with `orbit database:create --server={$data->databaseServer} --instance={$instance->id}`, then run `orbit instance:setup {$instance->id}`.", 409);
                }
            }
            $clone = ($container && ! $source instanceof Instance) || $instance instanceof Instance && ! $instance->first_setup_pending
                ? null : $this->databases->plan($project, $name, $container);
            if ($source instanceof Instance) {
                $clone = $this->containerDatabase($clone, $data->databaseServer);
            }
            app(NativeInstanceProvisioningIntent::class)->assertCreateSupported($node);
            $sourceRoot = $this->nodeSettings->fromStored($node->settings)?->appsPath();
            if (! $container && (! is_string($sourceRoot) || StoragePath::tryParse($sourceRoot) === null)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Native creation requires an explicit apps.path.', 503);
            }
            if ($data->domain !== null && ! $project->type->isWebServing()) {
                throw new ResourceOperationException('instance.route_unserved', 'A Project that serves no web pages takes no domain.', 422);
            }
            if ($instance instanceof Instance && $instance->status === InstanceState::Active
                && ($instance->failed_step === 'setup' || $instance->first_setup_pending)
            ) {
                throw new ResourceOperationException('instance.setup_step_failed', 'Setup is incomplete. Run instance:setup before using this Instance.', 409);
            }
            if (! $instance instanceof Instance && $container) {
                $instance = Instance::query()->create([
                    'project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
                    'source_layout' => InstanceSourceLayout::Checkout, 'checkout_path' => '/var/lib/orbit/instance-reservations/'.Str::uuid(),
                    'source_prepare_id' => (string) Str::uuid(), 'root' => $root, 'branch_override' => $data->branch,
                    'status' => InstanceState::Reserved, 'first_setup_pending' => true,
                ]);
                // The checkout path names the workspace inside the container, which only the Instance's id names.
                $instance->update(['checkout_path' => InstanceContainer::workspaceOnNode($instance)]);
            }
            if ($container) {
                // The runtime row first: it makes the Process records target the container.
                app(InstanceContainer::class)->reserve($instance, $node, $source);
            }
            if (! $instance instanceof Instance) {
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

            if (! $container) {
                $instance = app(SelectInstanceSeedAction::class)->execute($instance);
            }
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
                'user' => $node->user, 'source_root' => $container ? InstanceContainer::workspaceOnNode($instance) : $sourceRoot,
                'participant_node_ids' => [$node->id],
                'seed_repository' => $instance->seed_repository, 'seed_commit' => $instance->seed_commit, 'seed_path' => $instance->seed_path,
                'source_layout' => $instance->source_layout,
                'route_id' => null, 'route_domain' => null,
                'setup_digest' => $digest(LifecyclePhase::Setup), 'teardown_digest' => $digest(LifecyclePhase::Teardown),
                'php_version' => $project->php_version, 'laravel' => $project->is_laravel === true,
            ];
            if ($selection !== null) {
                $input['branch_selection'] = $selection->toArray();
            }
            $input['ssh_execution'] = app(SwampSshExecutionInput::class)->forNode($node);
            // A web-serving Project gets its Route, the derived <name>.<project-slug>.<tld> or --domain. It is reserved
            // before the environment and the Processes are captured, because its domain changes both.
            // An already active Instance runs no phase, so it reserves no Route either.
            $alreadyActive = $instance->status === InstanceState::Active && ! $instance->first_setup_pending;
            $route = null;
            if (! $alreadyActive) {
                // Admitted Project facts; the Route site and the Process units render from them before the run.
                $instance->update(['selected_php_version' => $project->php_version, 'source_is_laravel' => $project->is_laravel === true]);
                if ($instance->requiresRoute()) {
                    $route = app(CreateRouteAction::class)->ensureForInstance($instance, $data->domain);
                    $this->admission->assertRouteDomain($route->domain);
                    $input = [...$input, 'route_id' => $route->id, 'route_domain' => $route->domain];
                }
            }
            $provisioning = app(NativeInstanceProvisioningIntent::class)->create($instance, $input);
            // An already active Instance runs no phase, so admission records no environment or Process for it.
            $input['environment_keys'] = $input['mail_keys_set'] = $input['members'] = [];
            $database = $copy = $server = null;
            if ($provisioning['already_active'] !== true) {
                // The Instance's own database is attached under DB before .env renders, so .env already names it: a
                // database on --database-server or on the default Instance's MySQL server, else a copy of its SQLite file.
                $serverSlug = $data->databaseServer ?? $clone?->server?->slug;
                $server = $serverSlug === null ? null : $this->serverDatabase($instance, $serverSlug, $clone);
                $copy = $server === null && $clone !== null ? $this->sqliteCopy($instance, $node, $clone) : null;
                $database = $copy['intent'] ?? null;
                app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForDevelopment($instance);
                $input['environment_keys'] = app(DevelopmentCreateEnvironment::class)->admit($instance, $project, $copy['connection'] ?? $server['connection'] ?? null, $source);
                $input['mail_keys_set'] = app(DevelopmentCreateEnvironment::class)->mailKeys($instance);
                // A rerun after a failed create checks the Processes its first run already started.
                $input['members'] = $container ? [] : app(ProcessInstanceOperation::class)->capture($instance, 'process.install_instance_definitions', includeActive: true)['members'];
            }
            if ($route !== null) {
                // The Route publishes the Instance awake only when this run starts every one of its Processes.
                $input = [...$input, ...app(NativeRouteAdmission::class)->capture('route.create', $route, ['mode' => 'converge'], $node->id,
                    preserveEnvironment: true, preparedWorkloads: true,
                    wakePreparedWorkloads: ! $container && ! $instance->processes()->whereNotIn('id', array_column($input['members'], 'id'))->exists())];
            }
            // A clone runs no deploy steps: the default ran them before its seed snapshot.
            $steps = $container && $provisioning['already_active'] !== true
                ? ($source instanceof Instance ? [] : app(DevelopmentDeploySteps::class)->capture($project)) : null;
            $input['direct_provisioning'] = [...$provisioning, 'route' => $input['route_create'] ?? null, 'database' => $database,
                'server_database' => $server['input'] ?? null, 'container' => $steps === null ? null
                    : $this->containerIntent($instance, $node, $steps, $source, $server['connection'] ?? null,
                        ValidatedData::string(ValidatedData::object($provisioning['resolve'])['branch']), $route?->domain)];
            if ($selection !== null && is_array($input['direct_provisioning']['container'])) {
                $input['direct_provisioning']['container']['branch_selection'] = $selection->toArray();
            }
            if ($steps !== null) {
                // F8: no container create has run yet, so the run starts at 30 minutes, plus the deploy step timeouts. A clone runs
                // no deploy step but adds its database copy and its T3 start.
                $input['run_budget_seconds'] = 1800 + array_sum(array_column($steps, 'timeout_seconds')) + ($server === null ? 0 : 180);

                return $this->operations->execute('instance.create', 'instance', $instance->id, $node->id, $input, $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
            }
            // F8: 60 s for the create phases, the admitted setup timeouts (the setup phase runs within 540 s),
            // 30 s for the Process phase, 180 s for the Route child (CSR, signing, install, publication and its TLS probe; 117 s measured cold),
            // 60 s more for the withdrawal of a Route when the create fails (20 s measured),
            // and 180 s for the database copy or the owned server database (its create, owner check and fill).
            $input['run_budget_seconds'] = 60 + min(LifecycleStep::MaxTotalTimeoutSeconds, array_sum(array_map(
                static fn (LifecycleStep $step): int => $step->timeoutSeconds, app(ProjectLifecycleStepStore::class)->ordered($project, LifecyclePhase::Setup)))) + 30
                + (isset($input['route_create']) ? 240 : 0) + ($database === null && $server === null ? 0 : 180);

            return $this->operations->execute('instance.create', 'instance', $instance->id, $node->id, $input, $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }

    /**
     * The owner, database and test databases on a Database server, recorded as `database:create --instance` records
     * them and created by the run's `operation-database-create` child. A source on that server is the default Instance's
     * database, which the run then copies in: a MySQL dump (ADR 0191), or a PostgreSQL template copy for a container clone.
     *
     * @return array{connection:DatabaseConnection,input:array<string,mixed>}
     */
    private function serverDatabase(Instance $instance, string $serverSlug, ?InstanceDatabaseClonePlan $clone): array
    {
        $prepared = app(CreateServerDatabaseAction::class)->prepareForInstance($serverSlug, $instance);
        $connection = DatabaseConnection::query()->findOrFail(ValidatedData::integer($prepared->input['connection_id']));
        DatabaseConnectionTarget::query()->updateOrCreate(['instance_id' => $instance->id, 'prefix' => 'DB'],
            ['database_connection_id' => $connection->id, 'network' => 'wireguard']);
        $same = $clone?->server !== null && $clone->server->id === $connection->database_server_id;

        return ['connection' => $connection, 'input' => ['intent_id' => ValidatedData::integer($prepared->input['intent_id']),
            'connection_id' => $connection->id, 'server_id' => $prepared->targetId, 'node_id' => $prepared->nodeId,
            'source' => $same ? $clone->connection->database : null, 'source_owner' => $same ? $clone->connection->username : null]];
    }

    /**
     * The default Instance's SQLite file becomes this Instance's own database at the same relative path in its checkout
     * (ADR 0191), copied with SQLite's backup API by the run before setup.
     *
     * @return array{connection:DatabaseConnection,intent:array<string,mixed>}
     */
    private function sqliteCopy(Instance $instance, Node $node, InstanceDatabaseClonePlan $clone): array
    {
        $relative = ValidatedData::string($clone->relativePath);
        $target = rtrim($instance->checkout_path, '/').'/'.$relative;
        $slug = OwnedDatabaseSlug::for($instance);
        $connection = DatabaseConnection::query()->firstOrCreate(['slug' => $slug],
            ['driver' => DatabaseDriver::Sqlite->value, 'node_id' => $instance->node_id, 'owner_instance_id' => $instance->id, 'path' => $target]);
        if ($connection->owner_instance_id !== $instance->id || $connection->path !== $target) {
            throw new ResourceOperationException('instance.database_slug_taken', 'The database connection slug for this Instance belongs to another owner.', 409);
        }
        DatabaseConnectionTarget::query()->updateOrCreate(['instance_id' => $instance->id, 'prefix' => 'DB'],
            ['database_connection_id' => $connection->id, 'network' => 'wireguard']);
        $source = $clone->source;

        return ['connection' => $connection, 'intent' => ['candidate' => ['ssh_execution' => app(SwampSshExecutionInput::class)->forNode($source->node)],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($node), 'user' => $node->user,
            'sqlite' => ['source' => rtrim($source->checkout_path, '/').'/'.$relative, 'user' => $source->node->user,
                'snapshot' => '/tmp/orbit-create-sqlite-'.$instance->id.'.sqlite', 'target' => $target]]];
    }

    /**
     * Whether this create makes a container Instance, and of which default (docs/reference/development-containers.md#lifecycle). The
     * Project's one `default` in the fleet is a container on a Node of the build list, and every other Instance of the Project is a
     * clone of it on its Node.
     *
     * @return array{0: bool, 1: Instance|null} Whether it is a container, and the default it clones
     */
    private function container(Project $project, Node $node, string $name, ?Instance $instance, CreateInstanceData $data): array
    {
        if ($instance instanceof Instance) {
            $runtime = $instance->runtime()->first();
            $source = $runtime?->clone_source_instance_id === null ? null : Instance::query()->findOrFail($runtime->clone_source_instance_id);

            return [$runtime !== null, $source];
        }
        $default = $name === 'default' ? null : Instance::query()->where('project_id', $project->id)->where('name', 'default')->first();
        if ($default instanceof Instance && $default->runtime()->exists()) {
            if ($default->node_id !== $node->id) {
                throw new ResourceOperationException('instance.default_missing',
                    "The Project's default Instance runs on Node [{$default->node_id}]. Create its other Instances there.", 409);
            }
            $this->admission->readyDefault($project, $default);
            $this->assertContainerProject($project);
            $this->admission->assertRuntime();

            return [true, $default];
        }
        if (! app(ContainerNodes::class)->includes($node)) {
            return [false, null];
        }
        if ($name !== 'default') {
            if (! $default instanceof Instance && ! $project->nested_fleet) {
                throw new ResourceOperationException('instance.default_missing', 'Create the Project\'s default Instance on this Node first.', 409);
            }

            return [false, null];
        }
        $this->assertContainerProject($project);

        return [true, null];
    }

    private function assertContainerProject(Project $project): void
    {
        app(InstanceContainer::class)->assertPhp($project);
    }

    /**
     * A clone's database: the default's database on a Database server is cloned on that same server. A dump to another server is a later
     * part of the runtime. A database in the workspace, such as SQLite, comes with the ZFS clone, so it needs no copy.
     */
    private function containerDatabase(?InstanceDatabaseClonePlan $clone, ?string $databaseServer): ?InstanceDatabaseClonePlan
    {
        if ($clone?->server === null) {
            if ($databaseServer !== null) {
                throw new ResourceOperationException('instance.database_clone_unsupported', 'The default Instance has no database on a Database server to clone.', 422);
            }

            return null;
        }
        if ($databaseServer !== null && $databaseServer !== $clone->server->slug) {
            throw new ResourceOperationException('instance.database_clone_unsupported',
                "A clone's database is cloned on the default's server [{$clone->server->slug}]; a copy to another server is not available yet.", 422);
        }

        return $clone;
    }

    /**
     * The container, its runtime row and, for the Project's default, its development deploy steps, which the run copies to the vault.
     *
     * @param  list<array{name:string,phase:string,required:bool,timeout_seconds:int,command_digest:string}>  $steps
     * @return array<string,mixed>
     */
    private function containerIntent(Instance $instance, Node $node, array $steps, ?Instance $source, ?DatabaseConnection $database, string $branch, ?string $domain): array
    {
        $containers = app(InstanceContainer::class);

        // The default stays on the Project's default branch, which its deployments follow; a clone takes its own branch.
        return [...$containers->intent($instance, $containers->reserve($instance, $node, $source), $database, $source instanceof Instance ? $branch : '',
            $domain === null ? null : 'https://'.$domain), 'steps' => $steps];
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
