<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\Instances\RemoteProductionRouteApplicationUrlWriter;
use App\Infrastructure\Instances\RemoteProductionWebRootManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\PhpBuildAnswers;

describe('production web roots', function (): void {

    it('checks one web root in the selected release and names it when the release cannot serve it', function (): void {
        [$instance] = prodweb_instance();
        $agents = new FakeAgentCommandRunner()->answer('instance.check-served-web-roots', []);
        $manager = new RemoteProductionWebRootManager($agents);
        $manager->assertServable($instance, 'apps/docs/public');

        expect($agents->calls[0]['input']['served'])->toBe([['web_root' => 'apps/docs/public', 'directory' => 'apps/docs']]);
        $agents->refuse('instance.check-served-web-roots', 'instance.release_invalid', ['check' => 'the selected release holds the web root as a directory']);
        expect(fn () => $manager->assertServable($instance, 'apps/docs/public'))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('app-prod.web_root_invalid')
                ->and($exception->getMessage())->toContain('apps/docs/public'));
    });

    it('asks the agent to write APP_URL with a new key and the stored name for a served directory', function (): void {
        [$instance] = prodweb_instance();
        $agents = new FakeAgentCommandRunner()->answer('instance.write-directory-url', ['written' => true]);

        new RemoteProductionRouteApplicationUrlWriter($agents)->configureDirectoryUrl($instance, 'apps/docs', 'https://docs.example.com');

        $input = $agents->calls[0]['input'];
        expect(array_diff_key($input, ['app_key' => true]))->toBe([
            'user' => 'orbit-acme', 'home' => '/home/orbit-acme', 'directory' => 'apps/docs', 'url' => 'https://docs.example.com',
            'app_name' => 'APP_NAME="Acme"', 'app_name_stored' => false,
        ])
            ->and($input['app_key'])->toStartWith('APP_KEY="base64:');
    });

    it('adds a pool for each served directory to the runtime it converges', function (): void {
        [$instance] = prodweb_instance();
        $agents = new FakeAgentCommandRunner()->answer('php.install-build', PhpBuildAnswers::result())->answer('php.configure-runtime', ['changed' => false, 'reloaded' => false])->answer('php.converge-production-runtime', ['published' => true]);
        $manager = new RemoteProductionPhpRuntimeManager(new ProductionPhpRuntimeConfigRenderer, $agents);
        $manager->converge($instance);
        prodweb_route($instance, 'docs.example.com', 'apps/docs/public');
        $manager->converge($instance);
        [$before, $after] = array_column(array_column($agents->calls, 'input'), 'configuration');
        $suffix = substr(hash('sha256', 'apps/docs'), 0, 8);

        expect($after['pool'])->toStartWith($before['pool'])
            ->toContain("[orbit-orbit-acme-{$suffix}]\nuser = orbit-acme\n")
            ->toContain("listen = /run/php/orbit-acme.{$suffix}.sock\n")
            ->toContain("chdir = /home/orbit-acme/current/apps/docs\n")
            ->and($after['initial_pool'])->toContain("chdir = /home/orbit-acme/releases/initial/apps/docs\n");
    });
});

/** @return array{Instance} */
function prodweb_instance(): array
{
    $node = Node::query()->create([
        'name' => 'prod-web',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.72',
        'wireguard_ip' => '10.44.0.72',
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($node, true);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'checkout',
        'checkout_path' => '/home/orbit-acme/releases/initial',
        'production_user' => 'orbit-acme',
        'production_home' => '/home/orbit-acme',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance->refresh(), '8.4')->attributes());
    prodweb_route($instance, 'acme.example.com');

    return [$instance->refresh()];
}

function prodweb_route(Instance $instance, string $domain, ?string $webRoot = null): Route
{
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'domain' => $domain,
        ...($webRoot === null ? [] : ['web_root' => $webRoot]),
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route->refresh();
}
