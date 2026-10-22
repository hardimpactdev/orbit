<?php

declare(strict_types=1);

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Clusters\ClusterState;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\TaskVms\TaskVmPackageCacheMount;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Instances\DependencyUpdateSupervisorHost;
use App\Infrastructure\Logs\CacheLogStreamStore;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Laravel\Ai\Classification;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;
use Tests\Support\FakeHostKeyScanner;
use Tests\Support\FakeNodeAgentRuntime;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\FakeRouterLanIngressReconciler;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\FakeTaskVmPackageCacheMount;
use Tests\Support\FakeVitePortRuntime;
use Tests\Support\GatewayReleaseFixture;
use Tests\Support\GuardsSudo;
use Tests\Support\LocalInstanceTransferTransport;
use Tests\Support\NullTaskReviewDiff;
use Tests\Support\RealStorageGuard;
use Tests\Support\SshGuardedProcessRunner;
use Tests\Support\TestToolchain;
use Tests\Support\UnreachableSshExecutor;
use Tests\Support\WithSharedGitFixtureCleanup;
use Tests\TestCase;

require_once __DIR__.'/Support/FakeNodeAgentRuntime.php';
require_once __DIR__.'/Support/Orb245TransferFakes.php';
require_once __DIR__.'/Support/AgentDriverTestSupport.php';
require_once __DIR__.'/Support/TaskNodeReachability.php';
require_once __DIR__.'/Support/TaskVmGroups.php';
require_once __DIR__.'/Support/TaskSchedulerTickFixtures.php';
require_once __DIR__.'/Support/ResponseFixtures.php';
require_once __DIR__.'/Support/RedactionPcreFailure.php';
require_once __DIR__.'/Support/ValkeyDeadlineClock.php';
require_once __DIR__.'/Helpers/MetricsRoleFixtures.php';
require_once __DIR__.'/Helpers/WebSocketRoleFixtures.php';
require_once __DIR__.'/Helpers/AgentViewFixtures.php';
require_once __DIR__.'/Helpers/AnalyticsRoleFixtures.php';
require_once __DIR__.'/Helpers/AnalyticsConnectionFixtures.php';
require_once __DIR__.'/Helpers/InstanceAnalyticsFixtures.php';
require_once __DIR__.'/Helpers/ConfigFixtures.php';
require_once __DIR__.'/Helpers/TaskVmHostFixtures.php';
require_once __DIR__.'/Helpers/GatewayReleaseFixtures.php';
require_once __DIR__.'/Helpers/CliReleaseFixtures.php';
require_once __DIR__.'/Helpers/DevelopmentDefaultFixtures.php';
require_once __DIR__.'/Helpers/OperationFixtures.php';

uses(GuardsSudo::class)->in('Feature', 'Unit', 'Fixtures', 'Rehearsal');

uses(TestCase::class, LazilyRefreshDatabase::class, WithCachedConfig::class, WithCachedRoutes::class)
    ->beforeEach(function (): void {
        $this->realStorageGuard = RealStorageGuard::watch(storage_path('app'));
        // Direct-file staging must be isolated too, not just writes through the Storage facade.
        $this->temporaryStorage = sys_get_temp_dir().'/orbit-feature-storage-'.bin2hex(random_bytes(16));
        app()->useStoragePath($this->temporaryStorage);
        Storage::fake('local');
        app()->instance(SshExecutor::class, new UnreachableSshExecutor);
        app()->instance(TaskVmPackageCacheMount::class, new FakeTaskVmPackageCacheMount);
        app()->instance(HostKeyScanner::class, new FakeHostKeyScanner);
        $processes = new SshGuardedProcessRunner(app(NativeProcessRunner::class));
        app()->instance(SshGuardedProcessRunner::class, $processes);
        app()->instance(NativeProcessRunner::class, $processes);
        app()->instance(VitePortRuntime::class, new FakeVitePortRuntime);
        app()->instance(NodeAgentRuntime::class, new FakeNodeAgentRuntime);
        app()->instance(RouterLanIngressReconciler::class, new FakeRouterLanIngressReconciler);
        app()->instance(ClusterRouterDnsSelectionReconciler::class, new FakeClusterRouterDnsSelectionReconciler);
        app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
        app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
        test_share_scheduler_graph();
        app()->instance(TaskReviewDiff::class, new NullTaskReviewDiff);
        // A Node Caddy build runs local `sudo` on a Gateway Node; tests record build requests instead.
        app()->instance(NodeCaddyBuilds::class, new FakeNodeCaddyBuilds);
        // The view's file store under ORBIT_HOME would outlive a test; each test gets its own.
        app()->instance(CacheAgentStateView::class, new CacheAgentStateView(Cache::store('array')));
        app()->instance(LogStreamStore::class, new CacheLogStreamStore(Cache::store('array')));
        // The same holds for the Node locks' file store.
        app()->instance(NodeLocks::class, new NodeLocks(Cache::store('array')));
        Classification::fake()->preventStrayClassifications();
        // Transitions wait for private DNS answers to expire; tests assert those waits instead.
        Sleep::fake(syncWithCarbon: true);
        // A streamed response a test never consumed stays armed; each test starts with none.
        ActivityShutdownFinalizer::forgetArmed();
    })
    ->afterEach(function (): void {
        try {
            $this->realStorageGuard->assertUnchanged();
            app(SshGuardedProcessRunner::class)->assertNoStrayProcesses();
        } finally {
            File::deleteDirectory($this->temporaryStorage);
        }
    })
    ->in('Feature');

uses(WithSharedGitFixtureCleanup::class)
    ->beforeAll(fn () => GatewayReleaseFixture::prepare())
    ->in('Feature/GatewayReleases');

uses()
    ->beforeAll(fn () => GatewayReleaseFixture::prepareAdopted())
    ->in(
        'Feature/GatewayReleases/AbandonGatewayReleaseTest.php',
        'Feature/GatewayReleases/FinalizeGatewayReleaseTest.php',
        'Feature/GatewayReleases/GatewayReleaseExecutionOwnerTest.php',
        'Feature/GatewayReleases/GatewayReleaseOperationsWiringTest.php',
        'Feature/GatewayReleases/GatewayReleaseProcessDeclarationTest.php',
        'Feature/GatewayReleases/GatewayReleaseTickConfirmationTest.php',
        'Feature/GatewayReleases/GatewayReleaseFixtureTest.php',
        'Feature/GatewayReleases/SystemdGatewayReleaseDrainTest.php',
    );

uses(WithSharedGitFixtureCleanup::class)
    ->beforeAll(fn () => LocalInstanceTransferTransport::prepare())
    ->in('Feature/Api/TransferInstanceTest.php', 'Feature/Infrastructure/Instances/TransferEnvironmentModeTest.php', 'Feature/Infrastructure/Instances/RemoteInstanceTransferSourceTest.php');

// Each moved extension's tests run with its switch on (docs/reference/extensions.md#the-runtime-switch).
uses()
    ->beforeEach(function (): void {
        app(ExtensionStore::class)->set('documents', true);
    })
    ->in('Feature/Extensions/Documents');

uses()
    ->beforeEach(function (): void {
        app(ExtensionStore::class)->set('vault', true);
    })
    ->in('Feature/Extensions/Vault');

uses()
    ->beforeEach(function (): void {
        app(ExtensionStore::class)->set('conn', true);
    })
    ->in('Feature/Extensions/Conn');

/** @param list<array{name: string, phase: string, command: string, timeout_seconds: int}> $steps */
function store_deploy_steps(Instance $instance, array $steps): void
{
    app(InstanceDeployStepStore::class)->replaceAll(
        $instance,
        array_map(static fn (array $step): DeploymentStep => new DeploymentStep(
            $step['name'],
            DeploymentPhase::from($step['phase']),
            $step['command'],
            $step['timeout_seconds'],
        ), $steps),
    );
}

/** @return list<array{name: string, phase: string, command: string, timeout_seconds: int}> */
function orbit_test_set_app_placement_role(Node $node, bool $production): void
{
    $role = $production ? RoleName::AppProd : RoleName::AppDev;

    if ($node->exists) {
        NodeRole::query()->updateOrCreate(
            ['node_id' => $node->id, 'role' => $role],
            ['status' => LifecycleStatus::Active],
        );

        return;
    }

    $node->setRelation('roles', collect([
        new NodeRole(['role' => $role, 'status' => LifecycleStatus::Active]),
    ]));
}

function normalized_deploy_steps(Instance $instance): array
{
    return array_map(
        static fn (DeploymentStep $step): array => $step->toArray(),
        app(InstanceDeployStepStore::class)->ordered($instance),
    );
}

/** @return array{Node, Node, Project, Instance} */
function deployment_api_fixture(): array
{
    $caller = Node::query()->create([
        'name' => 'deployment-gateway-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.140',
        'wireguard_ip' => '10.44.0.140',
        'user' => 'orbit',
    ]);
    $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $owner = Node::query()->create([
        'name' => 'deployment-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.141',
        'wireguard_ip' => '10.44.0.141',
        'user' => 'orbit',
    ]);
    $owner->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create([
        'name' => 'Deployment API',
        'slug' => 'deployment-api',
        'repository_url' => 'https://example.test/deployment-api.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $owner->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/deployment-api/releases/initial',
        'production_user' => 'deployment-api',
        'production_home' => '/home/deployment-api',
        'branch' => 'main',
        'branch_override' => 'main',
        'status' => 'source_resolved',
    ]);

    return [$caller, $owner, $project, $instance->fresh()];
}

/**
 * Returns the host variant for running a dependency update supervisor program in a test. Linux runs the exact
 * Node program. Other hosts, such as macOS, have no /proc, so they run the same program logic with the
 * Portable variant, which reads process state with ps.
 */
function dependency_update_supervisor_host(): DependencyUpdateSupervisorHost
{
    return PHP_OS_FAMILY === 'Linux' ? DependencyUpdateSupervisorHost::Node : DependencyUpdateSupervisorHost::Portable;
}

/**
 * Adapt a Caddyfile with the installed caddy binary, which must meet the release floor in
 * App\Domain\Nodes\CaddyRelease. Reads the configuration from a temporary file rather than stdin.
 */
function caddy_adapt(string $configuration): CommandResult
{
    $path = tempnam(sys_get_temp_dir(), 'orbit-caddy-adapt-');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary Caddyfile.');
    }
    try {
        file_put_contents($path, $configuration);

        return new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: [TestToolchain::require('caddy', 'brew install caddy'), 'adapt', '--config', $path, '--adapter', 'caddyfile'],
        ));
    } finally {
        unlink($path);
    }
}

/**
 * @return array{Node, Node}
 */
function router_lan_topology(): array
{
    $cluster = Cluster::query()->create([
        'name' => 'lan-cluster',
        'state' => ClusterState::Active,
    ]);
    $router = router_lan_node('lan-router', '10.44.0.10', '10.20.0.10', $cluster);
    $eligible = router_lan_node('lan-member', '10.44.0.11', '10.20.0.11', $cluster);
    $router->roles()->create([
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
        'cluster_id' => $cluster->id,
    ]);

    return [$router->fresh('cluster') ?? $router, $eligible];
}

/** @return list<Node> */
function router_lan_denied_sources(Cluster $cluster): array
{
    $other = Cluster::query()->create([
        'name' => 'other-lan-cluster',
        'state' => ClusterState::Active,
    ]);

    return [
        router_lan_node('vpn-only', '10.44.0.12', null, $cluster),
        router_lan_node('inactive-member', '10.44.0.13', '10.20.0.13', $cluster, LifecycleStatus::Failed),
        router_lan_node('standalone', '10.44.0.14', '10.20.0.14'),
        router_lan_node('other-cluster', '10.44.0.15', '10.20.0.15', $other),
        router_lan_node('lan-only', '10.44.0.16', '10.20.0.16', $cluster, publicKey: null),
        router_lan_node('public-source', '10.44.0.17', '10.20.0.17'),
    ];
}

function router_lan_node(
    string $name,
    string $wireguardIp,
    ?string $lanIp,
    ?Cluster $cluster = null,
    LifecycleStatus $status = LifecycleStatus::Active,
    ?string $publicKey = 'wg-key',
): Node {
    return Node::query()->create([
        'name' => $name,
        'cluster_id' => $cluster?->id,
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.str_replace('10.44.0.', '', $wireguardIp),
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
        'lan_ip' => $lanIp,
        'wireguard_public_key' => $publicKey,
    ]);
}

/** @return array<string, mixed> */
function test_assistance_attributes(AssistanceKind $kind, ?string $question, string $reason): array
{
    return ['assistance_requested' => true, 'assistance_kind' => $kind,
        'assistance_question' => $kind === AssistanceKind::Direction ? $question : null, 'assistance_reason' => $reason];
}

/** Seed current task assistance through the same writer as production. */
function test_update_task(Task|Builder|Relation $task, array $attributes): bool
{
    if (! $task instanceof Task) {
        foreach ($task->get() as $record) {
            test_update_task($record, $attributes);
        }

        return true;
    }
    $assistance = array_intersect_key($attributes, array_flip(TaskAssistance::Fields));
    $task->update(array_diff_key($attributes, $assistance));
    if ($assistance !== []) {
        $requested = $assistance['assistance_requested'] ?? $task->assistance_requested;
        $kind = $requested ? ($assistance['assistance_kind'] ?? $task->assistance_kind ?? AssistanceKind::Failure) : null;
        $kind = is_string($kind) ? AssistanceKind::from($kind) : $kind;
        TaskAssistance::apply($task, $kind,
            $assistance['assistance_question'] ?? $task->assistance_question,
            $assistance['assistance_reason'] ?? $task->assistance_reason,
            replaceFailure: true, replaceDirection: true);
    }

    return true;
}

/** Create first, then request assistance against the stored task. */
function test_create_task(Builder|Relation $query, array $attributes): Task
{
    $assistance = array_intersect_key($attributes, array_flip(TaskAssistance::Fields));
    $task = $query->create(array_diff_key($attributes, $assistance));
    test_update_task($task, $assistance);

    return $task;
}
