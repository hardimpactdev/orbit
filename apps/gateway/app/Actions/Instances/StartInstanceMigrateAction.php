<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\InstanceMigration\InstanceMigrationRoute;
use App\Domain\InstanceMigration\MigratedProcessDefinitions;
use App\Domain\InstanceMigration\MigrateSchedules;
use App\Domain\Instances\ContainerNodes;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceEgress;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Admits the move of one checkout Instance into a container on its Node (docs/cli/instance/migrate.mmd) and records its whole
 * intent. It changes no Node. The runtime row is reserved here; the checkout stays the Instance's runtime until the run succeeds.
 * The command is temporary (docs/reference/old-paths.md).
 */
final readonly class StartInstanceMigrateAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private InstanceContainer $containers,
        private InstanceMigrationRoute $routes,
    ) {}

    public function execute(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = $this->idempotency->replay(InstanceMigration::Kind)) !== null) {
            return $replay;
        }

        return $this->admit($instance, $caller, $requestId);
    }

    private function admit(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $instance = Instance::query()->with(['project', 'node'])->lockForUpdate()->findOrFail($instance->id);
            $previous = Operation::query()->where('kind', InstanceMigration::Kind)->where('target_type', 'instance')
                ->where('target_id', $instance->id)->latest('id')->first();
            $recovery = $previous?->result['recovery'] ?? null;
            if ($previous?->error_code === 'instance.migrate_result_unavailable'
                && is_array($recovery) && ($recovery['runtime_reservation'] ?? null) === 'retained'
                && $instance->runtime()->where('operation_id', $previous->id)->exists()) {
                throw new ResourceOperationException('instance.migrate_recovery_required',
                    ValidatedData::nullableString($previous->result['error_message'] ?? null)
                        ?? "Operation [{$previous->id}] has no verified terminal receipt. The owner must recover its receipt or reconcile rollback or cutover. No Orbit command repairs this evidence gap.",
                    409, details: ['previous_operation_id' => $previous->id,
                        'missing_evidence' => $previous->result['missing_evidence'] ?? ['finish_output'],
                        'repair_command' => null]);
            }
            if ($previous !== null && (! $previous->status->terminal() || InstanceMigrateProjection::unsettled($previous))) {
                if (! $previous->status->terminal()) {
                    $status = match ($previous->status) {
                        OperationStatus::Running => 'running',
                        OperationStatus::Queued => 'queued',
                        default => 'waiting',
                    };
                    throw new ResourceOperationException('instance.migrate_settlement_pending',
                        "A migrate of this Instance is {$status} (Operation [{$previous->id}]). Wait for it to end.",
                        409, details: ['previous_operation_id' => $previous->id, 'retryable' => true]);
                }
                $limit = InstanceMigrateProjection::settlementLimit($previous)?->toISOString();
                $when = $limit === null ? 'Its run deadline starts when its wait ends; the hold limit is 60 minutes after expiry.'
                    : "Its target hold limit is {$limit}; the reconciler closes an unavailable result on its first pass after that limit.";
                throw new ResourceOperationException('instance.migrate_settlement_pending',
                    "Operation [{$previous->id}] awaits the reconciler. Retry after settlement. {$when}",
                    409, details: ['previous_operation_id' => $previous->id, 'retryable' => true, 'settlement_hold_limit' => $limit]);
            }
            $node = $instance->node;
            $this->assertCheckout($instance, $node);
            // Naming decides default behavior; migration never consumes the default's seed.
            $default = $instance->name === 'default' ? null : $this->projectDefault($instance);
            if ($instance->processes()->where(static fn (Builder $query): Builder => $query
                ->where('status', LifecycleStatus::Removing)->orWhereNotNull('retired_runtime'))->exists()
                || Operation::query()->where('target_type', 'process')->whereIn('target_id', $instance->processes()->select('id'))
                    ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('instance.lifecycle_busy', 'An Instance Process has an unfinished operation or runtime cleanup.', 409);
            }
            $sourceProcesses = app(ContainerProcesses::class)->source($instance);
            $sourceSchedules = app(MigrateSchedules::class)->plans($instance);
            $this->assertPhp($instance->project);
            $route = $this->route($instance);
            $databases = $this->databases($instance);
            // Keep a failed restore's reservation: its leftover container and firewall rules still use that address and T3 port.
            // The checkout's site, as it serves before the move.
            $before = $route === null ? null : $this->routes->before($route);
            $hadReservation = $instance->runtime()->exists();
            $runtime = $this->containers->reserve($instance, $node);
            app(MigratedProcessDefinitions::class)->assertCanCapture($instance);
            $branch = $instance->branch ?? ValidatedData::string($instance->project->default_branch);
            // The Node's mirror fetches the branch from the checkout itself: commits that were never pushed come along, and it needs no token.
            $container = [...$this->containers->intent($instance, $runtime, $databases['servers'][0] ?? null, $branch, $route === null ? null : 'https://'.$route->domain),
                'processes' => app(ContainerProcesses::class)->units($instance, migrate: true),
                'repository' => $instance->checkout_path, 'protocols' => ['file'], 'steps' => []];
            $endpoints = $container['bridge_endpoints'];
            if (! is_array($endpoints)) {
                throw new LogicException('The container access plan must expose its endpoints.');
            }
            $environmentTargets = array_map(static function (mixed $value): array {
                $target = ValidatedData::object($value);

                return ['host' => ValidatedData::string($target['host']), 'port' => ValidatedData::integer($target['port'])];
            }, array_values($endpoints));
            $reach = array_map($this->endpoint(...), $databases['servers']);
            $publication = $route === null || $before === null ? null : $this->routes->capture($instance, $route, $runtime, $before);

            $operation = $this->operations->execute(InstanceMigration::Kind, 'instance', $instance->id, $node->id, [
                'instance_id' => $instance->id, 'project_id' => $instance->project_id, 'node_id' => $node->id,
                'participant_node_ids' => array_values(array_unique([$node->id, ...($publication['participant_node_ids'] ?? [])])),
                'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($node),
                'route_id' => $route?->id, 'route_publication' => $publication,
                'sqlite_connection_ids' => $databases['sqlite'], 'had_runtime_reservation' => $hadReservation,
                'migrate' => [
                    'checkout' => $instance->checkout_path, 'branch' => $branch, 'default' => $instance->name === 'default',
                    'application' => ApplicationDirectory::resolve($instance->checkout_path, $instance->root ?? $instance->project->root),
                    'awake' => ['directory' => RuntimeHibernation::MarkerDirectory, 'path' => RuntimeHibernation::awakePath(RuntimeHibernation::key($instance->id))],
                    'environment' => [
                        'targets' => $environmentTargets,
                        'node_addresses' => array_values(array_filter([$node->wireguard_ip, $node->lan_ip, $node->public_ssh_host])),
                        'proxy_policy' => ['private_v4' => InstanceEgress::PrivateV4, 'private_domains' => app(PeerDnsPolicy::class)->domains()],
                    ],
                    'databases' => $reach, 'processes' => $sourceProcesses, 'schedules' => $sourceSchedules, 'firewall_prefix' => InstanceContainer::firewallPrefix($instance),
                    'retire' => $this->retire($instance, $default),
                ],
                'container' => $container,
            ], $caller, $requestId);
            $runtime->update(['operation_id' => $operation->id]);

            return $operation;
        });
    }

    private function assertCheckout(Instance $instance, Node $node): void
    {
        if ($instance->runtime()->exists() && ! InstanceMigration::pending($instance)) {
            throw $this->unsupported("Instance [{$instance->name}] already runs in a container.");
        }
        if (! $instance->placedOnAppDev() || ! app(ContainerNodes::class)->includes($node)) {
            throw $this->unsupported('Only a development checkout Instance on a Node of the build list moves into a container.');
        }
        if ($instance->status === InstanceState::Removing || $instance->removalMember()->exists() || InstanceMigration::running($instance)
            || Operation::query()->where('kind', 'instance.deploy')->where('target_type', 'instance')->where('target_id', $instance->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()
            || InstanceTransfer::query()->where('instance_id', $instance->id)->where('status', '!=', 'completed')->exists()
            || Instance::query()->where('clone_candidate_id', $instance->id)->whereNull('clone_completed_at')->exists()) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance is busy with another lifecycle operation.', 409);
        }
        if ($instance->status !== InstanceState::Active || $instance->first_setup_pending) {
            throw $this->unsupported('Only an active Instance moves into a container.');
        }
    }

    /** A non-default keeps its Project placement and T3 prerequisites, without consuming a default snapshot. */
    private function projectDefault(Instance $instance): Instance
    {
        $default = Instance::query()->where('project_id', $instance->project_id)->where('name', 'default')->first();
        if (! $default instanceof Instance) {
            throw new ResourceOperationException('instance.default_missing', 'The Project has no default Instance.', 409);
        }
        if ($default->node_id !== $instance->node_id) {
            throw new ResourceOperationException('instance.default_missing',
                "The Project's default Instance runs on Node [{$default->node_id}]. Its other Instances live there.", 409);
        }
        app(InstanceLifecycleContributions::class)->assertCreateAvailable();
        if (! app()->bound(TaskVmModelRoute::class)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'An Instance with T3 needs the model router.', 503);
        }
        app(TaskVmModelRoute::class)->assertReady();

        return $default;
    }

    /** A web-serving Project names its PHP version, and the runtime folder holds that build. The refusal names the fix. */
    private function assertPhp(Project $project): void
    {
        if ($project->type->isWebServing() && $project->php_version === null) {
            throw new ResourceOperationException('project.source_defaults_incomplete',
                "Project [{$project->slug}] has no PHP version. Set `require.php` in `composer.json` on [{$project->default_branch}], then run `orbit project:update {$project->id} --source-access=github_app` to record it before migrating.", 422);
        }
        $this->containers->assertPhp($project);
    }

    /** The Instance's own Route, which must serve: the move replaces its site in one publication. */
    private function route(Instance $instance): ?Route
    {
        $routes = $instance->routes()->orderBy('routes.id')->get();
        if ($routes->count() > 1 || $routes->contains(static fn (Route $route): bool => $route->web_root !== null)) {
            throw $this->unsupported('An Instance with more than its own Route does not move into a container yet.');
        }
        $route = $routes->first();
        if ($route instanceof Route && ($route->status !== RouteStatus::Active || ! $route->sites_published)) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance\'s Route is not active.', 409);
        }

        return $route;
    }

    /**
     * The Instance's databases. One on a Database server stays where it is, and the container gets its way to it; `DB` comes first.
     * A SQLite file in the checkout moves with the files, so its connection record goes when the move has succeeded.
     *
     * @return array{servers: list<DatabaseConnection>, sqlite: list<int>}
     */
    private function databases(Instance $instance): array
    {
        $servers = $sqlite = [];
        $targets = $instance->databaseConnectionTargets()->with('databaseConnection.server')
            ->orderByRaw("prefix = 'DB' desc")->orderBy('id')->get();
        foreach ($targets as $target) {
            $connection = $target->databaseConnection;
            if ($connection->driver !== DatabaseDriver::Sqlite) {
                $servers[$connection->id] = $connection;

                continue;
            }
            if (! is_string($connection->path) || ! str_starts_with($connection->path, rtrim($instance->checkout_path, '/').'/')) {
                throw new ResourceOperationException('instance.database_clone_unsupported', 'The Instance\'s SQLite database is not inside its checkout.', 422);
            }
            $sqlite[] = $connection->id;
        }

        return ['servers' => array_values($servers), 'sqlite' => array_values(array_unique($sqlite))];
    }

    /** @return array{host: string, port: int} */
    private function endpoint(DatabaseConnection $connection): array
    {
        return ['host' => ValidatedData::string($connection->host), 'port' => ValidatedData::integer($connection->port)];
    }

    /**
     * What the move removes once it has succeeded. A default keeps its checkout while another checkout Instance of its Project is left
     * on the Node: those are worktrees of its repository. The move of the last of them removes the default's old checkout too, when
     * the Gateway still knows its path from the default's own move and the Node shows that the worktree belongs to it.
     *
     * @return list<array{path: string, mirror: string, common_of?: string}>
     */
    private function retire(Instance $instance, ?Instance $default): array
    {
        $others = Instance::query()->where('project_id', $instance->project_id)->where('node_id', $instance->node_id)
            ->whereKeyNot($instance->id)->get()->contains(static fn (Instance $other): bool => ! $other->runtime()->exists() || InstanceMigration::pending($other));
        if ($default === null) {
            return $others ? [] : [['path' => $instance->checkout_path, 'mirror' => InstanceContainer::mirror($instance)]];
        }
        $retire = [['path' => $instance->checkout_path, 'mirror' => InstanceContainer::mirror($instance)]];
        $moved = $others ? null : Operation::query()->where('kind', InstanceMigration::Kind)->where('target_type', 'instance')
            ->where('target_id', $default->id)->where('status', OperationStatus::Succeeded)->orderByDesc('id')->first();
        $kept = $moved === null ? null : (ValidatedData::object($moved->input['migrate'] ?? [])['checkout'] ?? null);
        if (is_string($kept) && $kept !== '' && $kept !== $instance->checkout_path) {
            $retire[] = ['path' => $kept, 'mirror' => InstanceContainer::mirror($default), 'common_of' => $instance->checkout_path];
        }

        return $retire;
    }

    private function unsupported(string $message): ResourceOperationException
    {
        return new ResourceOperationException('instance.migrate_unsupported', $message, 409);
    }
}
