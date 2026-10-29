<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Instances\RemoteProductionInstanceSourceLifecycle;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

it('derives production serving paths through current and resolves PHP roots after a release switch', function (): void {
    $node = Node::query()->create([
        'name' => 'release-layout',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.216',
        'wireguard_ip' => '10.44.0.216',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Release layout',
        'slug' => 'release-layout',
        'repository_url' => 'https://example.test/release-layout.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-216/releases/initial',
        'production_user' => 'orbit-app-216',
        'production_home' => '/home/orbit-app-216',
        'production_php_socket' => '/run/php/orbit-app-216.sock',
        'selected_php_version' => '8.5',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'release-layout.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $site = new DevelopmentSiteRepository()
        ->forNode($node)
        ->sole();
    $production = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));
    $development = new DevelopmentCaddyConfigRenderer()->render(collect([
        new DevelopmentSite(
            nodeId: $node->id,
            nodeAddress: '10.44.0.216',
            scope: 'development',
            checkoutPath: '/home/orbit/site',
            documentRoot: 'public',
            phpVersion: '8.5',
            domain: 'development.test',
        ),
    ]));

    expect($instance->load('project')->effectiveRoot())
        ->toBe('/home/orbit-app-216/current/public')
        ->and($site->checkoutPath)
        ->toBe('/home/orbit-app-216/current')
        ->and($site->documentRoot)
        ->toBe('public')
        ->and($production)
        ->toContain(
            'root * /home/orbit-app-216/current/public',
            'php_fastcgi unix//run/php/orbit-app-216.sock {',
            'resolve_root_symlink',
        )
        ->and($development)
        ->toContain('php_fastcgi unix//run/php/orbit-development.sock')
        ->not->toContain('resolve_root_symlink');
});

it('uses the current-release root instead of a flat production home', function (): void {
    $node = Node::query()->create([
        'name' => 'flat-production',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.218',
        'wireguard_ip' => '10.44.0.218',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Flat production',
        'slug' => 'flat-production',
        'repository_url' => 'https://example.test/flat-production.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-218',
        'production_user' => 'orbit-app-218',
        'production_home' => '/home/orbit-app-218',
        'selected_php_version' => '8.5',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'flat-production.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $site = new DevelopmentSiteRepository()
        ->forNode($node)
        ->sole();
    $configuration = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));

    expect($instance->load('project')->effectiveRoot())
        ->toBe('/home/orbit-app-218/current/public')
        ->and($site->checkoutPath)
        ->toBe('/home/orbit-app-218/current')
        ->and($configuration)
        ->not->toContain('root * /home/orbit-app-218/public');
});

// The Gateway's part of the home lifecycle: what it sends and how it reads each answer. The agent's Rust tests in
// apps/agent/src/commands/production_home_tests.rs prove what each command does to a home.

it('prepares the recorded user, its source, and its branch, and reads the profile', function (): void {
    [$source, $agents, $instance] = production_home_lifecycle(fn (FakeAgentCommandRunner $agents) => $agents
        ->answer('instance.prepare-production-user', [])
        ->answer('instance.prepare-production-source', [])
        ->answer('instance.resolve-production-source', ['branch' => 'main', 'commit' => str_repeat('a', 40)])
        ->answer('instance.inspect-production-profile', ['kind' => 'none', 'entry_point' => null, 'composer' => null])
        ->answer('instance.grant-production-caddy-access', []));

    $source->prepareUser($instance);
    $source->prepareSource($instance, false);
    $resolution = $source->resolve($instance);
    $profile = $source->inspectProfile($instance);
    $source->prepareCaddyAccess($instance);

    $home = ['repository' => 'https://example.test/release-home.git', 'user' => 'orbit-app-216', 'home' => '/home/orbit-app-216', 'instance' => $instance->id];
    expect($resolution->branch)->toBe('main')
        ->and($profile->phpVersion)->toBeNull()
        ->and(array_column($agents->calls, 'command'))->toBe([
            'instance.prepare-production-user', 'instance.prepare-production-source', 'instance.resolve-production-source',
            'instance.inspect-production-profile', 'instance.grant-production-caddy-access',
        ])
        ->and($agents->calls[0]['input'])->toBe(['user' => 'orbit-app-216', 'home' => '/home/orbit-app-216'])
        ->and(array_diff_key($agents->calls[1]['input'], ['git_read' => true]))->toBe([...$home, 'allow_existing' => false])
        ->and(array_diff_key($agents->calls[2]['input'], ['git_read' => true]))->toBe([...$home, 'relative_root' => 'public', 'application_suffix' => '', 'branch' => 'main', 'clone_target' => false])
        ->and($agents->calls[3]['input'])->toBe([
            'user' => 'orbit-app-216', 'relative_root' => 'public', 'checkout' => '/home/orbit-app-216/releases/initial',
            'application' => '/home/orbit-app-216/releases/initial', 'entry_point' => 'artisan',
        ])
        ->and($agents->calls[4]['input'])->toBe(['user' => 'orbit-app-216', 'home' => '/home/orbit-app-216', 'relative_root' => 'public', 'clone_target' => false]);
});

it('classifies the Composer metadata the agent read and refuses unsafe metadata', function (array $answer, ?string $errorCode): void {
    [$source, , $instance] = production_home_lifecycle(fn (FakeAgentCommandRunner $agents) => $agents->answer('instance.inspect-production-profile', $answer));

    if ($errorCode === null) {
        expect($source->inspectProfile($instance)->phpVersion)->toBe('8.4');

        return;
    }

    expect(fn () => $source->inspectProfile($instance))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe($errorCode));
})->with([
    'composer' => [['kind' => 'composer', 'entry_point' => 'regular', 'composer' => base64_encode('{"require":{"php":"~8.4.0","laravel/framework":"^12.0"}}')], null],
    'unsafe' => [['kind' => 'unsafe', 'entry_point' => null, 'composer' => null], 'app-prod.source_metadata_unsafe'],
    'partial' => [['kind' => 'partial', 'entry_point' => null, 'composer' => null], 'app-prod.source_metadata_unsafe'],
]);

it('refuses to inspect a recorded checkout outside the home or its releases before it contacts the Node', function (): void {
    [$source, $agents, $instance] = production_home_lifecycle();
    $instance->update(['checkout_path' => '/home/orbit-app-216/elsewhere/initial']);

    expect(fn () => $source->inspectProfile($instance->refresh()))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-prod.source_metadata_unsafe'));
    expect($agents->calls)->toBe([]);
});

it('leaves legacy flat production layouts outside release validation and cleanup', function (): void {
    [$layout, $agents, $instance] = production_home_lifecycle();
    $instance->update(['checkout_path' => '/home/orbit-app-216']);

    $layout->validateCurrent($instance->refresh());
    $layout->clearCurrent($instance);

    expect($agents->calls)->toBe([]);
});

it('validates and clears the selection and removes the home of the recorded identity', function (): void {
    [$layout, $agents, $instance] = production_home_lifecycle(fn (FakeAgentCommandRunner $agents) => $agents
        ->answer('instance.validate-release-layout', ['current' => 'initial'])
        ->answer('instance.clear-current-release', ['current' => null])
        ->refuse('instance.remove-production-home', 'instance.release_invalid', ['check' => 'no process of the account runs']));

    $layout->validateCurrent($instance);
    $layout->clearCurrent($instance);

    expect(fn () => $layout->remove($instance))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->step)->toBe('production-source-cleanup')
            ->and($exception->errorCode)->toBe('app-prod.source_cleanup_failed'));
    expect($agents->calls[0]['input'])->toBe([
        'repository' => 'https://example.test/release-home.git', 'user' => 'orbit-app-216', 'home' => '/home/orbit-app-216',
        'instance' => $instance->id, 'relative_root' => 'public', 'application_suffix' => '',
    ])
        ->and($agents->calls[2]['input'])->toBe(['repository' => 'https://example.test/release-home.git', 'user' => 'orbit-app-216', 'home' => '/home/orbit-app-216', 'instance' => $instance->id]);
});

it('refuses to remove a home that is not the Project production account', function (): void {
    [$layout, $agents, $instance] = production_home_lifecycle();
    $instance->update(['production_user' => 'orbit-app-999', 'production_home' => '/home/orbit-app-999']);

    expect(fn () => $layout->remove($instance->refresh()))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-prod.identity_missing'));
    expect($agents->calls)->toBe([]);
});

/** @return array{RemoteProductionInstanceSourceLifecycle, FakeAgentCommandRunner, Instance} */
function production_home_lifecycle(?Closure $configure = null): array
{
    $node = Node::query()->create(['name' => 'release-home', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.216', 'wireguard_ip' => '10.44.0.216', 'user' => 'orbit']);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->forceCreate(['id' => 216, 'name' => 'Release home', 'slug' => 'release-home', 'type' => 'laravel-app', 'repository_url' => 'https://example.test/release-home.git', 'default_branch' => 'main', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production', 'environment' => 'production',
        'source_layout' => 'release', 'checkout_path' => '/home/orbit-app-216/releases/initial', 'production_user' => 'orbit-app-216',
        'production_home' => '/home/orbit-app-216', 'root' => 'public', 'branch' => 'main',
    ]);
    $agents = new FakeAgentCommandRunner;
    if ($configure !== null) {
        $configure($agents);
    }

    return [new RemoteProductionInstanceSourceLifecycle($agents, app(ComposerSourceClassifier::class), app(RepositoryReadAccess::class)), $agents, $instance->refresh()];
}
