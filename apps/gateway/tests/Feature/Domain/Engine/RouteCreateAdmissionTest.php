<?php

declare(strict_types=1);

use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteEngineFlow;
use App\Domain\Routes\RouteWebRootCommands;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A Node with these roles under a fixed ID, as the plan names it. */
function admissionNode(int $id, array $roles): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->forceCreate(['id' => $id, 'name' => "node-{$id}", 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => "10.44.0.{$id}", 'public_ssh_host' => "10.44.0.{$id}", 'user' => 'orbit']));
    foreach ($roles as $role) {
        $node->roles()->create(['role' => $role, 'status' => 'active']);
    }

    return $node;
}

/** A route_create plan for a Route on app-dev Node 3 with DNS on VPN Node 1, with the account's observed home and group. */
function admissionPlan(array $overrides = []): array
{
    return [...[
        'route_id' => 9, 'domain' => 'shop.orbit', 'lan' => [], 'ingress_ports' => [], 'acl' => [], 'links' => [], 'php_files' => [], 'php_tests' => [], 'php_reloads' => [],
        'leaves' => [['node_id' => 3, 'scope' => 'app-instance-7', 'domain' => 'shop.orbit', 'root_hash' => str_repeat('a', 64),
            'directory' => '/etc/caddy/orbit-certificates/app-instance-7/versions/0123456789abcdef',
            'current' => '/etc/caddy/orbit-certificates/app-instance-7/current', 'target' => 'versions/0123456789abcdef', 'user' => 'orbit']],
        'caddy' => [['node_id' => 3, 'content' => "shop.orbit {\n}\n"]], 'url_lines' => [], 'env_files' => [], 'probes' => [], 'ingress' => [],
        'dns' => [['node_id' => 1, 'records' => "address=/shop.orbit/10.44.0.3\n", 'catalog' => "{}\n"]], 'awake' => [],
        'leaf_indexes' => [0], 'trust' => [3], 'root_certificate' => "-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n",
        'site_nodes' => [3], 'nodes' => ['3' => ['account' => 'orbit']],
    ], ...$overrides];
}

function admissionOperation(array $plan, array $input = []): Operation
{
    return (new Operation)->forceFill(['id' => 41, 'kind' => 'route.create', 'input' => ['route_create' => $plan, 'route_lifecycle' => ['effectful' => true, 'workloads' => []], ...$input]]);
}

beforeEach(function (): void {
    config(['orbit.engine.kinds' => ['route.create']]);
    $this->dev = admissionNode(3, [RoleName::AppDev]);
    $this->vpn = admissionNode(1, [RoleName::Gateway, RoleName::Vpn]);
    $bus = Mockery::mock(MachineBus::class);
    $bus->shouldReceive('nodeActions')->andReturn(['tls.ensure_csr', 'account.read', 'file.read', 'file.ensure', 'dotenv.ensure', 'config.apply', 'systemd.unit.ensure', 'ca.trust.ensure']);
    $bus->shouldReceive('nodeFeatures')->andReturn(['tls.csr_input']);
    $this->app->instance(MachineBus::class, $bus);
    $this->routes = fn (Operation $operation): bool => app(EngineKinds::class)->routes($operation);
});

it('runs a development Route on the engine when every condition holds, a link another account owns included', function (): void {
    expect(($this->routes)(admissionOperation(admissionPlan())))->toBeTrue()
        ->and(($this->routes)(admissionOperation(admissionPlan(['links' => [['node_id' => 3, 'path' => '/home/shop/current', 'target' => 'releases/1', 'owner' => 'shop']]]))))->toBeTrue();
});

it('keeps a Route on Swamp when one condition fails', function (Closure $operation): void {
    expect(($this->routes)($operation()))->toBeFalse();
})->with([
    'Caddy access rows' => [fn () => admissionOperation(admissionPlan(['acl' => RouteWebRootCommands::access(3, ['user' => 'orbit', 'sites' => [
        ['checkout' => '/home/orbit/apps/shop', 'relative_root' => 'public', 'application' => '/home/orbit/apps/shop']]], (new Instance)->forceFill(['source_is_laravel' => true]))]))],
    'an app-prod site Node' => [fn () => admissionOperation(admissionPlan(['site_nodes' => [4], 'leaves' => [[...admissionPlan()['leaves'][0], 'node_id' => 4]], 'trust' => [4],
        'caddy' => [['node_id' => 4, 'content' => "x\n"]], 'nodes' => ['4' => ['account' => 'orbit']],
        'route_id' => admissionNode(4, [RoleName::AppProd])->id * 0 + 9]))],
    'an unclassified site Node' => [fn () => admissionOperation(admissionPlan(['site_nodes' => [5], 'leaves' => [[...admissionPlan()['leaves'][0], 'node_id' => 5]], 'trust' => [5],
        'caddy' => [['node_id' => 5, 'content' => "x\n"]], 'nodes' => ['5' => ['account' => 'orbit']],
        'route_id' => admissionNode(5, [])->id * 0 + 9]))],
    'a DNS Node without the vpn role' => [fn () => admissionOperation(admissionPlan(['dns' => [['node_id' => 6, 'records' => "r\n", 'catalog' => "{}\n"]],
        'route_id' => admissionNode(6, [RoleName::AppProd])->id * 0 + 9]))],
    'a production release in the intent' => [fn () => admissionOperation(admissionPlan(), ['production_control' => ['instance_id' => 7, 'release' => 'current']])],
    'a production workload in the intent' => [fn () => admissionOperation(admissionPlan(), ['route_lifecycle' => ['effectful' => true, 'workloads' => [['node_id' => 3, 'production' => true]]]])],
    'an account name outside the owner pattern' => [fn () => admissionOperation(admissionPlan(['nodes' => ['3' => ['account' => 'Orbit']],
        'leaves' => [[...admissionPlan()['leaves'][0], 'user' => 'Orbit']]]))],
    'a link owner outside the owner pattern' => [fn () => admissionOperation(admissionPlan(['links' => [['node_id' => 3, 'path' => '/home/orbit/current', 'target' => 'releases/1', 'owner' => 'Dev.Team']]]))],
    'a Node without account.read' => [function () {
        $bus = Mockery::mock(MachineBus::class);
        $bus->shouldReceive('nodeActions')->andReturn(['tls.ensure_csr', 'file.read', 'file.ensure', 'dotenv.ensure', 'config.apply', 'systemd.unit.ensure', 'ca.trust.ensure']);
        $bus->shouldReceive('nodeFeatures')->andReturn(['tls.csr_input']);
        app()->instance(MachineBus::class, $bus);

        return admissionOperation(admissionPlan());
    }],
]);

it('keeps a Route with Caddy access rows on Swamp until A4 lands, whatever its Node reports', function (array $features, bool $engine): void {
    $bus = Mockery::mock(MachineBus::class);
    $bus->shouldReceive('nodeActions')->andReturn(['tls.ensure_csr', 'account.read', 'file.read', 'file.ensure', 'dotenv.ensure', 'config.apply', 'systemd.unit.ensure',
        'ca.trust.ensure', 'posix.acl.ensure']);
    $bus->shouldReceive('nodeFeatures')->andReturn($features);
    $this->app->instance(MachineBus::class, $bus);
    $acl = RouteWebRootCommands::access(3, ['user' => 'orbit', 'sites' => [['checkout' => '/home/orbit/apps/shop', 'relative_root' => 'public',
        'application' => '/home/orbit/apps/shop']]], (new Instance)->forceFill(['source_is_laravel' => true]));

    expect(($this->routes)(admissionOperation(admissionPlan(['acl' => $acl]))))->toBe($engine);
})->with([
    'with posix.acl.tree' => [['tls.csr_input', 'posix.acl.tree'], false],
    'without it' => [['tls.csr_input'], false],
]);

it('keeps a Route to an Instance outside development on Swamp', function (): void {
    $prod = admissionNode(4, [RoleName::AppProd]);
    $project = Project::query()->create(['name' => 'shop', 'slug' => 'shop', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/nckrtl/shop.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $prod->id, 'name' => 'shop', 'checkout_path' => '/home/orbit/apps/shop', 'branch' => 'main',
        'vite_port' => 5173, 'status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $prod->id, 'provenance' => 'explicit', 'publication' => 'private',
        'domain' => 'shop.orbit', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);

    expect(($this->routes)(admissionOperation(admissionPlan(['route_id' => $route->id]))))->toBeFalse()
        ->and(fn () => app(RouteEngineFlow::class)->admit(admissionOperation(admissionPlan(['route_id' => $route->id]))))->toThrow(EngineUnsupported::class, 'outside development');
});

it('holds every Node of the plan, the VPN Node with DNS work alone included', function (): void {
    expect(RoutePublicationHolds::nodes(admissionOperation(admissionPlan())))->toBe([1, 3]);
});

it('persists main\'s chart step code for a failed step, a guard conflict included', function (string $step, string $code, string $persisted): void {
    $operation = Operation::query()->forceCreate(['kind' => 'route.create', 'status' => OperationStatus::Running, 'target_type' => 'route', 'target_id' => 9,
        'node_id' => null, 'input' => ['route_create' => admissionPlan(), 'route_lifecycle' => ['effectful' => false], 'execution_secrets' => []]]);
    $operation->forceFill(['state' => ['swamp_run_id' => 'engine-'.$operation->id]])->save();
    $failed = (new OperationEngineStep)->forceFill(['name' => $step, 'status' => 'failed', 'error_code' => $code, 'on_failure' => false, 'optional' => false]);

    app(RouteEngineFlow::class)->finish($operation, new EngineRun($operation->id, [$step => $failed], 0, 1));

    expect($operation->fresh()->error_code)->toBe($persisted);
})->with([
    'a site write' => ['site-3-cert-0', 'file.ensure_failed', 'route.publish_failed'],
    'a pool test' => ['site-3-pools', 'config.validate_failed', 'route.publish_failed'],
    'a guarded Caddyfile' => ['site-3-caddy', 'owned_revision_conflict', 'route.publish_failed'],
    'a dnsmasq check' => ['dns-0', 'config.validate_failed', 'private_dns.publication_failed'],
    'a guarded catalog' => ['dns-0', 'owned_revision_conflict', 'private_dns.publication_failed'],
    'a request' => ['csr-0', 'app-dev.certificate_request_failed', 'app-dev.certificate_request_failed'],
    'an account lookup error' => ['site-3-account-0', 'account.read_failed', 'route.publish_failed'],
    'an absent account' => ['site-3-accounts', 'route.account_missing', 'route.publish_failed'],
]);
