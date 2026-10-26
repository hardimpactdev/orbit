<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\ProjectInspectionData;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Infrastructure\Doctor\NativeProjectStateInspector;
use App\Infrastructure\Doctor\ProductionInstanceInspectionExpectationFactory;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;

it('batches only settled selected-node Project checkouts through the agent', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $node->update(['settings' => ['apps' => ['path' => '/fast/apps']]]);
    $instance = application_app_instance($project, $node);
    $instance->update(['checkout_path' => "/fast/apps/{$project->slug}/development"]);
    application_app_instance($project, application_inspector_node(), 'other-node');
    $removing = application_app_instance($project, $node, 'removing');
    application_mark_removing($removing);
    application_app_instance($project, $node, 'provisioning')->update([
        'status' => InstanceState::CheckoutPrepared, 'provisioning_step' => 'checkout',
    ]);
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-project', [
        'checkouts' => [['instance_id' => (int) $instance->id, 'matches' => true]],
    ]);

    expect(application_app_inspector($agents)->inspect($project, $node))->toEqual(new ProjectInspectionData(1, true));
    expect($agents->calls)->toBe([[
        'node' => $node->name, 'command' => 'doctor.inspect-project', 'input' => [
            'repository' => $project->repository_url,
            'checkouts' => [[
                'instance_id' => (int) $instance->id, 'path' => $instance->checkout_path,
                'root' => '/fast/apps', 'user' => 'nckrtl', 'production' => false,
            ]],
        ],
    ]]);
});

it('supplies recorded production home and owner without a shell', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $instance = application_production_app_instance($project, $node, 'base64:'.str_repeat('A', 44));
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-project', [
        'checkouts' => [['instance_id' => (int) $instance->id, 'matches' => true]],
    ]);

    expect(application_app_inspector($agents)->inspect($project, $node))->toEqual(new ProjectInspectionData(1, true));
    expect($agents->calls[0]['input']['checkouts'])->toBe([[
        'instance_id' => (int) $instance->id, 'path' => $instance->checkout_path,
        'root' => $instance->production_home, 'user' => $instance->production_user, 'production' => true,
    ]]);
});

it('preserves mismatching and unavailable checkout identities independently', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $failed = application_app_instance($project, $node, 'failed');
    $mismatch = application_app_instance($project, $node, 'mismatch');
    $healthy = application_app_instance($project, $node, 'healthy');
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-project', ['checkouts' => [
        ['instance_id' => (int) $failed->id, 'matches' => null],
        ['instance_id' => (int) $mismatch->id, 'matches' => false],
        ['instance_id' => (int) $healthy->id, 'matches' => true],
    ]]);

    expect(application_app_inspector($agents)->inspect($project, $node))
        ->toEqual(new ProjectInspectionData(3, false, [(int) $mismatch->id], [(int) $failed->id]));
    expect($agents->calls)->toHaveCount(1);
});

it('returns a healthy empty Project selection without a command', function (): void {
    $agents = new FakeAgentCommandRunner;
    expect(application_app_inspector($agents)->inspect(application_inspector_app(), application_inspector_node()))
        ->toEqual(new ProjectInspectionData(0, true));
    expect($agents->calls)->toBe([]);
});

it('keeps a failed or malformed Project observation unavailable', function (string $failure): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $instance = application_app_instance($project, $node);
    $agents = new FakeAgentCommandRunner;
    match ($failure) {
        'refusal' => $agents->refuse('doctor.inspect-project', 'doctor.project_input_invalid'),
        'transport' => $agents->answer('doctor.inspect-project', static fn (): array => throw new RuntimeException('lost transport')),
        'missing' => $agents->answerMalformed('doctor.inspect-project', []),
        'wrong identity' => $agents->answerMalformed('doctor.inspect-project', ['checkouts' => [['instance_id' => 0, 'matches' => true]]]),
        'wrong value' => $agents->answerMalformed('doctor.inspect-project', ['checkouts' => [['instance_id' => (int) $instance->id, 'matches' => 'true']]]),
    };

    expect(application_app_inspector($agents)->inspect($project, $node))
        ->toEqual(new ProjectInspectionData(1, true, [], [(int) $instance->id]));
})->with(['refusal', 'transport', 'missing', 'wrong identity', 'wrong value']);

it('refuses invalid Project intent before an agent call', function (): void {
    $project = application_inspector_app();
    $project->repository_url = 'not a repository';
    $agents = new FakeAgentCommandRunner;
    expect(fn () => application_app_inspector($agents)->inspect($project, application_inspector_node()))
        ->toThrow(DoctorInspectionException::class);
    expect($agents->calls)->toBe([]);
});

it('sends recorded Instance source intent through the agent after a Project rename', function (): void {
    $node = application_inspector_node();
    $project = application_inspector_app();
    $instance = application_app_instance($project, $node);
    $checkout = $instance->checkout_path;
    $project->update(['slug' => 'renamed-project']);
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-development-source', [
        'checkout' => true, 'layout' => true, 'origin' => true, 'history' => true,
    ]);

    expect(application_instance_inspector($agents)->inspect($instance->refresh()))
        ->toEqual(new InstanceInspectionData(true, true, true, true));
    expect($agents->calls)->toBe([[
        'node' => $node->name, 'command' => 'doctor.inspect-development-source', 'input' => [
            'repository' => $project->repository_url, 'checkout' => $checkout,
            'root' => '/srv/users/nckrtl/apps', 'user' => 'nckrtl', 'group' => 'nckrtl',
            'layout' => $instance->source_layout, 'starting_commit' => $instance->starting_commit,
        ],
    ]]);
});

it('fails Instance inspection closed for a checkout path that does not end in the Instance name', function (string $path): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $instance->update(['checkout_path' => $path]);
    $agents = new FakeAgentCommandRunner;

    expect(fn () => application_instance_inspector($agents)->inspect($instance->refresh()))
        ->toThrow(DoctorInspectionException::class);
    expect($agents->calls)->toBe([]);
})->with([
    'another name' => '/srv/users/nckrtl/apps/project/main',
    'too shallow' => '/development',
    'protected root' => '/srv/users/nckrtl/project/development',
]);

it('maps each development source mismatch independently', function (string $field, string $property): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $values = ['checkout' => true, 'layout' => true, 'origin' => true, 'history' => true];
    $values[$field] = false;
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-development-source', $values);

    expect(application_instance_inspector($agents)->inspect($instance)->{$property})->toBeFalse();
})->with([
    ['checkout', 'checkoutExists'], ['layout', 'repositoryLayoutMatches'],
    ['origin', 'originMatches'], ['history', 'sourceIdentityMatches'],
]);

it('sanitizes failed and malformed development source observations', function (string $failure): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $agents = new FakeAgentCommandRunner;
    match ($failure) {
        'refusal' => $agents->refuse('doctor.inspect-development-source', 'doctor.source_inspection_failed'),
        'transport' => $agents->answer('doctor.inspect-development-source', static fn (): array => throw new RuntimeException('remote-secret-token')),
        'missing' => $agents->answerMalformed('doctor.inspect-development-source', []),
        'unavailable' => $agents->answerMalformed('doctor.inspect-development-source', ['checkout' => null, 'layout' => true, 'origin' => true, 'history' => true]),
        'malformed' => $agents->answerMalformed('doctor.inspect-development-source', ['checkout' => 'private-output', 'layout' => true, 'origin' => true, 'history' => true]),
    };

    $exception = application_capture_exception(fn () => application_instance_inspector($agents)->inspect($instance));
    application_assert_sanitized($exception, 'remote-secret-token');
})->with(['refusal', 'transport', 'missing', 'unavailable', 'malformed']);

it('keeps migrated source inspectors behind the agent boundary', function (): void {
    expect(file_get_contents(app_path('Infrastructure/Doctor/NativeProjectStateInspector.php')))
        ->not->toContain('SshExecutor', 'RemoteCommand', 'ProcessRunner', '<<<');
    expect(file_get_contents(app_path('Infrastructure/Doctor/NativeInstanceStateInspector.php')))
        ->toContain('doctor.inspect-development-source', 'doctor.inspect-production-instance')
        ->not->toContain('SshExecutor', 'RemoteCommand', 'ProcessRunner', '<<<');
});

it('sends production intent and both rendered application pools through the agent', function (string $root, string $suffix): void {
    $instance = application_production_app_instance(application_inspector_app(), application_inspector_node(), 'doctor-private-secret');
    $instance->update(['root' => $root]);
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-production-instance', production_observations());

    $result = application_instance_inspector($agents)->inspect($instance->refresh());

    expect($result)->toEqual(new InstanceInspectionData(true, true, true, true, true, true, true, true, true, true));
    $call = $agents->calls[0];
    expect($call['node'])->toBe($instance->node->name);
    expect($call['command'])->toBe('doctor.inspect-production-instance');
    expect($call['input']['environment'])->toBe("APP_KEY=\"doctor-private-secret\"\n");
    expect($call['input']['application_suffix'])->toBe($suffix);
    expect($call['input']['runtime']['application'])->toBe($instance->production_home.'/current'.$suffix);
    expect($call['input']['runtime']['initial_application'])->toBe($instance->production_home.'/releases/initial'.$suffix);
    expect($call['input']['runtime']['configuration']['pool'])->toContain('chdir = '.$instance->production_home.'/current'.$suffix);
    expect($call['input']['runtime']['configuration']['initial_pool'])->toContain('chdir = '.$instance->production_home.'/releases/initial'.$suffix);
    expect($call['input']['caddy_sites'])->toHaveCount(1);
    expect(app(NodeCaddyfileRenderer::class)->render($instance->node)->content)->toContain($call['input']['caddy_sites'][0]);
    expect(json_encode($result))->not->toContain('doctor-private-secret');
    $expectation = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance);
    expect(json_encode($expectation))->not->toContain('doctor-private-secret');
    expect($expectation->__debugInfo())->toBe(['environment' => '[PROTECTED]']);
})->with([['public', ''], ['server/web/public', '/server/web']]);

it('sends no runtime intent for static sites and Laravel packages', function (bool $package): void {
    $project = application_inspector_app();
    if ($package) {
        $project->update(['type' => ProjectType::LaravelPackage]);
    }
    $instance = application_production_app_instance($project, application_inspector_node(), 'private');
    $instance->update([
        'selected_php_version' => $package ? '8.5' : null,
        'production_php_service' => null, 'production_php_pool' => null, 'production_php_socket' => null,
    ]);
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-production-instance', production_observations());

    expect(application_instance_inspector($agents)->inspect($instance->refresh())->phpFpmProjectionMatches)->toBeTrue();
    expect($agents->calls[0]['input']['runtime'])->toBeNull();
    expect($agents->calls[0]['input']['association'])->toBeTrue();
})->with([false, true]);

it('refuses incomplete production runtime intent before a command', function (): void {
    $instance = application_production_app_instance(application_inspector_app(), application_inspector_node(), 'private');
    $instance->update(['production_php_service' => null, 'production_php_pool' => null, 'production_php_socket' => null]);
    $agents = new FakeAgentCommandRunner;

    expect(fn () => application_instance_inspector($agents)->inspect($instance->refresh()))->toThrow(DoctorInspectionException::class);
    expect($agents->calls)->toBe([]);
});

it('maps production drift separately from unavailable observations', function (string $field, string $property, ?bool $value): void {
    $instance = application_production_app_instance(application_inspector_app(), application_inspector_node(), 'private');
    $values = production_observations();
    $values[$field] = $value;
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-production-instance', $values);

    expect(application_instance_inspector($agents)->inspect($instance)->{$property})->toBe($value);
})->with([
    ['home', 'productionHomeMatches', false], ['selection', 'releaseSelectionMatches', false],
    ['root', 'selectedReleaseRootMatches', false], ['environment', 'environmentProjectionMatches', false],
    ['php', 'phpFpmProjectionMatches', false], ['php', 'phpFpmProjectionMatches', null],
    ['caddy', 'caddyProjectionMatches', false], ['caddy', 'caddyProjectionMatches', null],
]);

it('sanitizes failed or malformed production command responses', function (string $failure): void {
    $instance = application_production_app_instance(application_inspector_app(), application_inspector_node(), 'private-secret');
    $agents = new FakeAgentCommandRunner;
    match ($failure) {
        'refusal' => $agents->refuse('doctor.inspect-production-instance', 'doctor.production_inspection_failed'),
        'transport' => $agents->answer('doctor.inspect-production-instance', static fn (): array => throw new RuntimeException('private-secret')),
        'missing' => $agents->answerMalformed('doctor.inspect-production-instance', []),
        'malformed' => $agents->answerMalformed('doctor.inspect-production-instance', [...production_observations(), 'php' => 'private-secret']),
    };

    application_assert_sanitized(application_capture_exception(fn () => application_instance_inspector($agents)->inspect($instance)), 'private-secret');
})->with(['refusal', 'transport', 'missing', 'malformed']);

/** @return array<string, bool> */
function production_observations(): array
{
    return ['home' => true, 'selection' => true, 'root' => true, 'environment' => true, 'php' => true, 'caddy' => true];
}

function application_capture_exception(Closure $operation): DoctorInspectionException
{
    try {
        $operation();
    } catch (DoctorInspectionException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected inspection failure.');
}

function application_assert_sanitized(DoctorInspectionException $exception, string $sentinel): void
{
    expect($exception->getMessage())
        ->toBeEmpty()
        ->and((string) $exception)
        ->not->toContain($sentinel)->and(json_encode($exception))
        ->not->toContain($sentinel);
}

function application_inspector_node(): Node
{
    static $number = 20;
    $number++;

    return Node::query()->create([
        'name' => "doctor-node-{$number}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => "10.44.0.{$number}",
        'tld' => "node-{$number}.test",
    ]);
}

function application_inspector_app(): Project
{
    static $number = 0;
    $number++;

    return Project::query()->create([
        'name' => "Project {$number}",
        'slug' => "project-{$number}",
        'repository_url' => "https://git.example.test/acme/project-{$number}.git",
    ]);
}

function application_app_instance(Project $project, Node $node, string $name = 'development'): Instance
{
    $project->update(['default_branch' => 'main', 'root' => 'public']);
    if (! $node->roles()->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])->exists()) {
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    }

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/users/nckrtl/apps/{$project->slug}/{$name}",
        'branch' => 'development',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ]);
}

function application_mark_removing(Instance $instance): void
{
    $trigger = DB::table('sqlite_master')
        ->where('type', 'trigger')
        ->where('name', 'instances_removal_status_update')
        ->value('sql');
    expect($trigger)->toBeString();
    DB::statement('DROP TRIGGER instances_removal_status_update');

    try {
        $instance->update(['status' => InstanceState::Removing]);
    } finally {
        DB::statement($trigger);
    }
}

function application_app_inspector(FakeAgentCommandRunner $agents): NativeProjectStateInspector
{
    return new NativeProjectStateInspector(
        $agents,
        new CommandDeadline,
        application_inspector_accounts(),
        app(StorageRootResolver::class),
        new NodeSettingsNormalizer,
    );
}

function application_instance_inspector(FakeAgentCommandRunner $agents): NativeInstanceStateInspector
{
    return new NativeInstanceStateInspector(
        new CommandDeadline,
        application_inspector_accounts(),
        new CheckoutRemovalBoundary(new ProtectedPathCatalog),
        app(ProductionInstanceInspectionExpectationFactory::class),
        $agents,
    );
}

function application_production_app_instance(Project $project, Node $node, string $secret): Instance
{
    NodeRole::query()->firstOrCreate(
        ['node_id' => $node->id, 'role' => RoleName::AppProd],
        ['status' => LifecycleStatus::Active],
    );
    $project->update(['default_branch' => 'main', 'root' => 'public']);
    $user = "orbit-app-{$project->id}";
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/{$user}/releases/initial",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => "{$project->slug}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => $secret]);

    return $instance;
}

function application_inspector_accounts(): ManagedUserAccountResolver
{
    return new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
        }
    };
}
