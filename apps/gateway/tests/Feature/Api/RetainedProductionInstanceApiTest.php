<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionInstanceSourceLifecycle;
use App\Domain\Instances\ProductionReleaseLayout;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteTarget;

beforeEach(function (): void {
    $this->destination = new class implements InstanceDestinationGuard
    {
        public bool $occupied = false;

        public function assertUnoccupied(Node $node, StoragePath $destination): void
        {
            if ($this->occupied) {
                throw new ResourceOperationException(
                    'instance.migration_conflict',
                    'Instance destination is occupied by unmanaged data.',
                    409,
                );
            }
        }
    };
    app()->instance(InstanceDestinationGuard::class, $this->destination);
    app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger
    {
        public function converge(Node $node, NodeRole $assignment): void {}

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

        public function removeUnreachable(Node $node, NodeRole $assignment): void {}
    });
    $this->configuration = new class implements DevelopmentInstanceConfigurator
    {
        public int $inspections = 0;

        public int $configurations = 0;

        /** @var list<string> */
        public array $urls = [];

        public ?string $phpVersion = '8.5';

        public bool $laravel = false;

        public function inspect(Instance $instance): DevelopmentSourceProfile
        {
            $this->inspections++;

            return new DevelopmentSourceProfile($this->phpVersion, $this->laravel);
        }

        public function configureLaravelUrl(Instance $instance, string $url): void
        {
            $this->configurations++;
            $this->urls[] = $url;
        }
    };
    app()->instance(DevelopmentInstanceConfigurator::class, $this->configuration);
    $this->projection = new class implements DevelopmentRouteProjector
    {
        public int $convergences = 0;

        public function converge(Instance $instance, Route $route): void
        {
            $this->convergences++;
        }
    };
    app()->instance(DevelopmentRouteProjector::class, $this->projection);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->source = new class implements DevelopmentInstanceSourceLifecycle
    {
        /** @var list<string> */
        public array $calls = [];

        /** @var list<bool> */
        public array $prepareExisting = [];

        public ?string $fail = null;

        public string $failureCode = 'instance.source_interrupted';

        /** @var array<string, mixed> Seed fields that real preparation records, which switch the layout to `worktree`. */
        public array $seed = [];

        public DevelopmentSourceResolution $resolution;

        public function __construct()
        {
            $this->resolution = new DevelopmentSourceResolution('dev', str_repeat('a', 40));
        }

        public function prepare(Instance $instance, bool $allowExisting): void
        {
            $this->prepareExisting[] = $allowExisting;
            if ($this->seed !== []) {
                $instance->update($this->seed);
            }
            $this->record('prepare', $instance);
        }

        public function inspectPrepared(Instance $instance): void
        {
            $this->record('inspect-prepared', $instance);
        }

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            $this->record('resolve', $instance);

            return $this->resolution;
        }

        public function inspectResolved(Instance $instance): DevelopmentSourceResolution
        {
            $this->record('inspect-resolved', $instance);

            return $this->resolution;
        }

        private function record(string $operation, Instance $instance): void
        {
            $this->calls[] = "{$operation}:{$instance->status->value}";

            if ($this->fail === $operation) {
                throw new ResourceOperationException($this->failureCode, 'Source operation interrupted.');
            }
        }
    };
    app()->instance(DevelopmentInstanceSourceLifecycle::class, $this->source);
    $this->productionSource = new class implements ProductionInstanceSourceLifecycle
    {
        /** @var list<string> */
        public array $calls = [];

        public bool $laravel = false;

        public ?string $phpVersion = '8.5';

        /** @var list<string> */
        public array $inspected = [];

        public function prepareUser(Instance $instance): void
        {
            $this->calls[] = 'user';
        }

        public function prepareSource(Instance $instance, bool $allowExisting): void
        {
            $this->calls[] = 'source:'.($allowExisting ? 'existing' : 'new');
        }

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            $this->calls[] = 'resolve';

            return new DevelopmentSourceResolution(
                $instance->branch_override ?? $instance->project->default_branch,
                str_repeat('b', 40),
            );
        }

        public function inspectProfile(Instance $instance): DevelopmentSourceProfile
        {
            $this->calls[] = 'profile';
            $this->inspected[] = $instance->checkout_path;

            return new DevelopmentSourceProfile($this->phpVersion, $this->laravel);
        }

        public function remove(Instance $instance): void {}

        public function prepareCaddyAccess(Instance $instance): void
        {
            $this->calls[] = 'access';
        }
    };
    app()->instance(ProductionInstanceSourceLifecycle::class, $this->productionSource);
    app()->instance(ProductionReleaseLayout::class, new class implements ProductionReleaseLayout
    {
        public function validateCurrent(Instance $instance): void {}

        public function clearCurrent(Instance $instance): void {}
    });
    $this->productionProjection = new class implements ProductionRouteProjector
    {
        /** @var list<string> */
        public array $calls = [];

        public function prepareRuntime(Instance $instance, Route $route): void
        {
            $this->calls[] = 'runtime';
        }

        public function prepareCertificate(Instance $instance, Route $route): void
        {
            $this->calls[] = 'certificate';
        }

        public function prepareFirewall(Instance $instance): void
        {
            $this->calls[] = 'firewall';
        }

        public function publish(Instance $instance, Route $route): void
        {
            $this->calls[] = 'route';
        }
    };
    app()->instance(ProductionRouteProjector::class, $this->productionProjection);
    $this->removalSource = new class implements DevelopmentInstanceSourceFinalizer, DevelopmentInstanceSourceRemoval
    {
        /** @var list<string> */
        public array $calls = [];

        public ?string $fail = null;

        public ?RuntimeConvergenceException $finalizeFailure = null;

        /** @var array<int, list<string>> */
        public array $linkedPaths = [];

        /** @var list<string>|null */
        public ?array $livePaths = null;

        /** @var array<int, InstanceSourceRevalidationState> */
        public array $states = [];

        public ?int $failPrepareFor = null;

        /** @var array<int, string> */
        public array $inspectionFailures = [];

        public function inspect(
            Instance $instance,
            bool $force,
            bool $inspectContent = true,
        ): InstanceSourceInventory {
            $this->record('inspect', $instance->id);

            if (isset($this->inspectionFailures[$instance->id])) {
                throw new RuntimeConvergenceException(
                    'app-instance-source-removal-inspect',
                    $this->inspectionFailures[$instance->id],
                    "Instance [{$instance->name}] source origin does not match the Project repository.",
                );
            }

            $paths = $this->linkedPaths[$instance->id] ?? $this->livePaths ?? [$instance->checkout_path];
            $payload = [
                'instance_id' => $instance->id,
                'layout' => $instance->source_layout,
                'repository_identity' => $instance->project->repository_identity,
                'checkout_path' => $instance->checkout_path,
                'root' => dirname(dirname($instance->checkout_path)),
                'branch' => $instance->branch,
                'starting_commit' => $instance->starting_commit,
                'common_repository_path' => $instance->source_layout === 'checkout'
                    ? $instance->checkout_path
                    : dirname(dirname($instance->checkout_path)).'/acme/default',
                'source_identity' => "test:{$instance->id}",
                'linked_worktree_paths' => $paths,
            ];

            return new InstanceSourceInventory(
                instanceId: $instance->id,
                layout: $instance->source_layout,
                repositoryIdentity: $instance->project->repository_identity,
                checkoutPath: $instance->checkout_path,
                root: dirname(dirname($instance->checkout_path)),
                branch: $instance->branch,
                startingCommit: $instance->starting_commit ?? '',
                commonRepositoryPath: $payload['common_repository_path'],
                sourceIdentity: "test:{$instance->id}",
                linkedWorktreePaths: $paths,
                digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            );
        }

        public function remove(
            Instance $instance,
            InstanceSourceInventory $inventory,
            bool $force,
        ): void {
            throw new LogicException('The durable coordinator does not call legacy source removal.');
        }

        public function prepare(
            InstanceRemovalMember $member,
            ?InstanceSourceRevalidationExpectation $expectation = null,
        ): void {
            $this->record('prepare', $member->instance_id);

            if ($this->failPrepareFor === $member->instance_id) {
                throw new ResourceOperationException(
                    'instance.source_interrupted',
                    'Source preparation interrupted.',
                    502,
                );
            }
        }

        public function revalidate(
            InstanceRemovalMember $member,
            ?InstanceSourceRevalidationExpectation $expectation = null,
        ): InstanceSourceRevalidationState {
            $this->record('revalidate', $member->instance_id);

            $state = $this->states[$member->instance_id] ?? InstanceSourceRevalidationState::Present;

            if ($state === InstanceSourceRevalidationState::Present && $this->livePaths !== null) {
                $required = $expectation?->requiredLinkedWorktreePaths ?? $member->linked_worktree_paths;
                $permitted = $expectation?->permittedLinkedWorktreePaths ?? $member->linked_worktree_paths;

                if (array_diff($required, $this->livePaths) !== [] || array_diff($this->livePaths, $permitted) !== []) {
                    throw new ResourceOperationException(
                        'instance.removal_conflict',
                        'The linked-worktree inventory changed after removal acceptance.',
                        409,
                    );
                }
            }

            return $state;
        }

        public function inspectRecorded(
            InstanceRemovalMember $member,
            InstanceSourceRevalidationState $state,
            ?InstanceSourceRevalidationExpectation $expectation = null,
        ): InstanceSourceInventory {
            $instance = Instance::query()->with('project')->findOrFail($member->instance_id);

            return $this->inspect($instance, (bool) $member->removal()->firstOrFail()->force);
        }

        public function finalize(
            InstanceRemovalMember $member,
            ?InstanceSourceRevalidationExpectation $expectation = null,
        ): string {
            $this->record('finalize', $member->instance_id);

            if ($this->finalizeFailure instanceof RuntimeConvergenceException) {
                throw $this->finalizeFailure;
            }

            $this->states[$member->instance_id] = InstanceSourceRevalidationState::Completed;

            if ($this->livePaths !== null) {
                $this->livePaths = array_values(array_diff($this->livePaths, [(string) $member->checkout_path]));
            }

            return hash('sha256', "receipt\0{$member->source_digest}");
        }

        private function record(string $operation, int $id): void
        {
            $this->calls[] = "{$operation}:{$id}";

            if ($this->fail === $operation) {
                if ($operation === 'revalidate') {
                    throw new ResourceOperationException(
                        'instance.removal_conflict',
                        'Source identity changed after removal acceptance.',
                        409,
                    );
                }

                throw new ResourceOperationException(
                    'instance.source_interrupted',
                    'Source operation interrupted.',
                    502,
                );
            }
        }
    };
    app()->instance(DevelopmentInstanceSourceRemoval::class, $this->removalSource);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $this->removalSource);
    $this->removalProjector = new class implements InstanceRemovalProjector
    {
        /** @var list<string> */
        public array $calls = [];

        public ?string $fail = null;

        public bool $failOnce = false;

        public function clearRouteTarget(InstanceRemovalMember $member): string
        {
            $this->record('route', $member);
            $route = Route::query()->find($member->route_id);

            if (! $route instanceof Route) {
                return 'deleted';
            }

            $route->targets()->where('instance_id', $member->instance_id)->delete();

            if ($route->targets()->exists()) {
                $route
                    ->targets()
                    ->orderBy('position')
                    ->get()
                    ->each(
                        static fn (RouteTarget $target, int $position) => $target->update(['position' => $position]),
                    );

                return 'retained';
            }

            $route->delete();

            return 'deleted';
        }

        public function withdrawPhpPool(InstanceRemovalMember $member): void {}

        public function cleanupRuntime(InstanceRemovalMember $member): void
        {
            $this->record('runtime', $member);
        }

        private function record(string $operation, InstanceRemovalMember $member): void
        {
            $this->calls[] = "{$operation}:{$member->instance_id}";

            if ($this->fail === $operation) {
                if ($this->failOnce) {
                    $this->fail = null;
                }

                throw new ResourceOperationException(
                    'instance.runtime_interrupted',
                    'Removal projection interrupted.',
                    502,
                );
            }
        }
    };
    app()->instance(InstanceRemovalProjector::class, $this->removalProjector);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->orbitApp = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
});

it('removes an existing production Instance through production source removal', function (): void {
    $node = create_app_prod_node('removal-prod');
    [$instance] = seed_active_production_app_instance($this->orbitApp, $node, 'production');
    $home = $instance->production_home;

    $this
        ->deleteJson("/api/v1/instances/{$instance->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect($home)
        ->toBe("/home/orbit-app-{$this->orbitApp->id}")
        ->and(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->removalSource->calls)
        ->toBe([])
        ->and($this->removalProjector->calls)
        ->toBe(["route:{$instance->id}", "runtime:{$instance->id}"]);
});

it('shows and removes an existing production Instance through inactive Cluster Node scope', function (): void {
    $node = create_app_prod_node('inactive-removal-prod');
    $cluster = Cluster::query()->create([
        'name' => 'inactive-production-removal',
        'state' => ClusterState::Inactive,
    ]);
    $node->update(['cluster_id' => $cluster->id]);
    [$instance] = seed_active_production_app_instance($this->orbitApp, $node, 'production');

    $this
        ->getJson("/api/v1/instances/{$instance->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.route.node_id', $node->id)
        ->assertJsonPath('data.route.cluster_id', null);

    $this
        ->deleteJson("/api/v1/instances/{$instance->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0);
});

it('retries existing production removal without recreating its deleted Route', function (): void {
    $node = create_app_prod_node('removal-retry-prod');
    [$instance] = seed_active_production_app_instance($this->orbitApp, $node, 'production-retry');
    $this->removalProjector->fail = 'runtime';

    $this
        ->deleteJson("/api/v1/instances/{$instance->id}")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.runtime_interrupted');
    expect(Route::query()->count())->toBe(0);

    $this->removalProjector->fail = null;
    $this
        ->deleteJson("/api/v1/instances/{$instance->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect(Route::query()->count())
        ->toBe(0)
        ->and(Instance::query()->count())
        ->toBe(0);
});

it('removes one target from a public clustered production Route and reports retained progress', function (): void {
    $cluster = Cluster::query()->create([
        'name' => 'production-removal',
        'state' => ClusterState::Active,
    ]);
    $instances = collect(['one', 'two'])->map(function (string $name) use ($cluster): Instance {
        $suffix = $name === 'one' ? '91' : '92';
        $node = Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => "app-prod-{$name}",
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => "192.0.2.{$suffix}",
            'wireguard_ip' => "10.44.0.{$suffix}",
        ]);
        $node->roles()->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Active,
        ]);

        return Instance::query()->create([
            'project_id' => $this->orbitApp->id,
            'node_id' => $node->id,
            'name' => $name,
            'environment' => 'production',
            'checkout_path' => "/var/www/acme/{$name}",
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::SourceResolved,
        ]);
    });
    $route = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'cluster_id' => $cluster->id,
        'domain' => 'production.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instances[0]->id, 'position' => 0]);
    $route->targets()->create(['instance_id' => $instances[1]->id, 'position' => 1]);
    $route->update(['status' => RouteStatus::Active]);
    $instances->each(static fn (Instance $instance) => $instance->update([
        'status' => InstanceState::Active,
    ]));

    $this
        ->deleteJson("/api/v1/instances/{$instances[0]->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $instances[0]->id)
        ->assertJsonPath('data.name', 'one')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.remaining', 0);

    expect(Instance::query()->pluck('id')->all())
        ->toBe([$instances[1]->id])
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Active)
        ->and($route->targets()->sole()->instance_id)
        ->toBe($instances[1]->id)
        ->and($route->targets()->sole()->position)
        ->toBe(0)
        ->and(InstanceRemovalMember::query()->sole()->route_outcome)
        ->toBe('retained')
        ->and($this->removalSource->calls)
        ->toBeEmpty();
});

function create_app_prod_node(string $name, ?string $tld = 'test'): Node
{
    $addressSuffix = Node::query()->count() + 30;
    $nodeTld = $tld === 'test' ? "{$name}.test" : $tld;
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => $nodeTld,
        'public_ssh_host' => "192.0.2.{$addressSuffix}",
        'wireguard_ip' => "10.44.1.{$addressSuffix}",
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);

    return $node->refresh();
}

/** @return array{Instance, Route} */
function seed_active_production_app_instance(
    Project $project,
    Node $node,
    string $name,
    ?string $domain = null,
    ?string $root = null,
    ?string $branchOverride = null,
    bool $flatHome = false,
): array {
    $user = "orbit-app-{$project->id}";
    $home = "/home/{$user}";
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'production',
        'source_layout' => InstanceSourceLayout::Checkout->value,
        'checkout_path' => $flatHome ? $home : "{$home}/releases/initial",
        'production_user' => $user,
        'production_home' => $home,
        'root' => $root,
        'branch' => $branchOverride ?? $project->default_branch,
        'branch_override' => $branchOverride,
        'starting_commit' => str_repeat('b', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => false,
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $resolvedHostname = $domain ?? "{$name}.{$project->slug}.{$node->tld}";
    $generated = $domain === null;
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $generated ? $node->id : null,
        'domain' => $resolvedHostname,
        'provenance' => $generated ? RouteProvenance::Generated : RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return [$instance->refresh(), $route->refresh()];
}
