<?php

declare(strict_types=1);

use App\Actions\Routes\SynchronizeRouteWebRootUrlsAction;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Instances\RouteApplicationUrlWriter;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Doctor\ProductionInstanceInspectionExpectationFactory;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Projects\NativeProjectUpdateProjectionMutator;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = web_root_node('dev-web', '10.44.0.20', production: false);
    $this->instance = web_root_instance($this->project, $this->node);
    $this->urls = new class implements RouteApplicationUrlWriter
    {
        /** @var list<array{string, string}> */
        public array $writes = [];

        public function configureDirectoryUrl(Instance $instance, string $relativeDirectory, string $url): void
        {
            $this->writes[] = [$relativeDirectory, $url];
        }
    };
    app()->instance(RouteApplicationUrlWriter::class, $this->urls);
});

describe('route web root', function (): void {
    it('refuses an invalid web root on create and update', function (): void {
        $own = web_root_route($this->instance, 'acme.web.test');

        foreach (['../docs/public', '/srv/docs/public', '.', 'apps//docs/public', 'apps/docs/public/', 'apps/./public'] as $webRoot) {
            $this->postJson('/api/v1/routes', ['instance_id' => $this->instance->id, 'domain' => 'docs.web.test', 'web_root' => $webRoot])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation.failed')
                ->assertJsonPath('error.details.web_root.0', fn (string $message): bool => str_contains($message, 'normalized relative path'));
            $this->patchJson("/api/v1/routes/{$own->id}", ['web_root' => $webRoot])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation.failed');
        }

        $this->postJson('/api/v1/routes', ['domain' => 'tool.web.test', 'node_id' => $this->node->id, 'upstream' => 'http://127.0.0.1:4000', 'web_root' => 'public'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.web_root.0', 'A custom proxy Route has no web root.');
        $this->patchJson("/api/v1/routes/{$own->id}", ['web_root' => 'apps/docs/public', 'publication' => 'private'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'route.web_root_update_separate');
        expect(Route::query()->count())->toBe(1)->and($own->refresh()->web_root)->toBeNull();
    });

    it('lists the stored Instance and directory Routes and refuses conflicting declarations', function (): void {
        $own = web_root_route($this->instance, 'acme.web.test');
        $payload = ['instance_id' => $this->instance->id, 'domain' => 'docs.acme.web.test', 'web_root' => 'apps/docs/public'];

        $docs = web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');

        $this->getJson("/api/v1/instances/{$this->instance->id}")
            ->assertOk()
            ->assertJsonPath('data.route.id', $own->id)
            ->assertJsonPath('data.domain', 'acme.web.test')
            ->assertJsonPath('data.routes.0.web_root', null)
            ->assertJsonPath('data.routes.1.id', $docs->id)
            ->assertJsonPath('data.routes.1.web_root', 'apps/docs/public');
        $this->postJson('/api/v1/routes', [...$payload, 'web_root' => 'apps/admin/public'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.retry_conflict');
        $this->postJson('/api/v1/routes', ['instance_id' => $this->instance->id, 'domain' => 'second.acme.web.test'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.target_conflict');
    });

    it('serves each web root from its own Caddy site, leaf, and PHP-FPM pool', function (): void {
        $own = web_root_route($this->instance, 'acme.web.test');
        $docs = web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        $alias = web_root_route($this->instance, 'alias.acme.web.test', 'public');
        $sites = web_root_sites($this->node);
        $checkout = $this->instance->checkout_path;
        $suffix = substr(hash('sha256', 'apps/docs'), 0, 8);

        expect($sites->get('acme.web.test')->poolName())->toBe("orbit-app-instance-{$this->instance->id}")
            ->and($sites->get('acme.web.test')->certificateDirectory())->toBe("/etc/caddy/orbit-certificates/app-instance-{$this->instance->id}/current")
            ->and($sites->get('docs.acme.web.test')->documentRoot)->toBe('apps/docs/public')
            ->and($sites->get('docs.acme.web.test')->poolName())->toBe("orbit-app-instance-{$this->instance->id}-{$suffix}")
            ->and($sites->get('docs.acme.web.test')->certificateDirectory())->toBe("/etc/caddy/orbit-certificates/route-{$docs->id}/current")
            ->and($sites->get('alias.acme.web.test')->poolName())->toBe("orbit-app-instance-{$this->instance->id}")
            ->and($sites->get('alias.acme.web.test')->certificateDirectory())->toBe("/etc/caddy/orbit-certificates/route-{$alias->id}/current");

        $caddy = new DevelopmentCaddyConfigRenderer()->render(collect([$sites->get('docs.acme.web.test')]));
        expect($caddy)->toContain("root * {$checkout}/apps/docs/public")
            ->toContain("unix//run/php/orbit-app-instance-{$this->instance->id}-{$suffix}.sock")
            ->toContain("/etc/caddy/orbit-certificates/route-{$docs->id}/current/cert.pem");

        $pools = new DevelopmentPhpFpmConfigRenderer()->render($sites->values(), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
        expect(substr_count($pools, '[orbit-app-instance-'))->toBe(2)
            ->and($pools)->toContain("[orbit-app-instance-{$this->instance->id}]\n")
            ->toContain("chdir = {$checkout}\n")
            ->toContain("[orbit-app-instance-{$this->instance->id}-{$suffix}]\n")
            ->toContain("chdir = {$checkout}/apps/docs\n");
        expect($own->refresh()->web_root)->toBeNull();
    });

    it('gives APP_URL to the Instance Route, then to the oldest Route serving a directory', function (): void {
        web_root_route($this->instance, 'acme.web.test');
        $docs = web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        web_root_route($this->instance, 'docs-two.acme.web.test', 'apps/docs/public');
        $alias = web_root_route($this->instance, 'alias.acme.web.test', 'public');
        $admin = web_root_route($this->instance, 'admin.acme.web.test', 'apps/admin/public');
        $winners = static fn (Instance $instance): array => array_map(
            static fn (Route $route): string => $route->domain,
            RouteWebRoot::applicationUrlRoutes($instance->refresh(), Route::query()->get()),
        );

        expect($winners($this->instance))->toBe(['apps/docs' => $docs->domain, 'apps/admin' => $admin->domain]);

        app(SynchronizeRouteWebRootUrlsAction::class)->execute($this->instance);
        expect($this->urls->writes)->toBe([['apps/docs', 'https://docs.acme.web.test'], ['apps/admin', 'https://admin.acme.web.test']]);

        $this->instance->update(['root' => 'apps/docs/public']);
        expect($winners($this->instance))->toBe(['' => $alias->domain, 'apps/admin' => $admin->domain]);
    });

    it('refuses conflicting web-root changes without changing the Instance Route', function (): void {
        $own = web_root_route($this->instance, 'acme.web.test');
        $docs = web_root_route($this->instance, 'docs.acme.web.test', 'apps/admin/public');
        $this->patchJson("/api/v1/routes/{$docs->id}", ['web_root' => null])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.target_conflict');
        $this->patchJson("/api/v1/routes/{$own->id}", ['web_root' => 'apps/docs/public'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.web_root_conflict');
        $this->patchJson("/api/v1/routes/{$docs->id}", ['domain' => 'other.acme.web.test'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.web_root_domain_immutable');
        expect($docs->refresh()->web_root)->toBe('apps/admin/public')
            ->and($own->refresh()->web_root)->toBeNull();
    });

    it('moves APP_URL to the new winner when the Project root changes', function (): void {
        web_root_route($this->instance, 'acme.web.test');
        web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        web_root_route($this->instance, 'alias.acme.web.test', 'public');
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->once();
        app()->instance(DevelopmentRouteProjector::class, $projector);
        $this->project->update(['root' => 'apps/docs/public']);

        app(NativeProjectUpdateProjectionMutator::class)->publishRoot($this->project, 'apps/docs/public', [
            'instances' => [['instance_id' => $this->instance->id]],
        ]);

        expect($this->urls->writes)->toBe([['', 'https://alias.acme.web.test']]);
    });

    it('refuses unsafe production web-root paths before storing a Route', function (string $trigger): void {
        [$instance] = web_root_production($this->project);
        web_root_route($instance, 'acme.example.com');
        $webRoot = $trigger === 'web root is its application directory' ? 'apps/docs' : 'public/docs/public';

        $response = $this->postJson('/api/v1/routes', ['instance_id' => $instance->id, 'domain' => 'docs.example.com', 'web_root' => $webRoot])
            ->assertConflict();

        expect($response->json('error.code'))->toBe('route.web_root_unsafe')
            ->and(Route::query()->where('domain', 'docs.example.com')->exists())->toBeFalse()
            ->and(RouteWebRoot::servedApplications($instance))->toBe([])
            ->and(web_root_production_deploy_served($instance))->toBe([]);
    })->with(['web root is its application directory', 'web root inside the Instance root']);

    it('refuses a production web root in the default directory other than the Instance root', function (): void {
        [$instance] = web_root_production($this->project);
        $instance->update(['root' => 'apps/site/public']);
        web_root_route($instance, 'acme.example.com');

        $this->postJson('/api/v1/routes', ['instance_id' => $instance->id, 'domain' => 'site.example.com', 'web_root' => 'apps/site'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.web_root_unsafe');
        web_root_route($instance, 'alias.example.com', 'apps/site/public');
        expect(RouteWebRoot::servedApplications($instance->refresh()))->toBe([]);
    });

    it('keeps the only Route of a production Instance without a web root', function (): void {
        [$instance] = web_root_production($this->project);
        $this->project->update(['type' => 'monorepo']);
        $own = web_root_route($instance, 'acme.example.com');

        $this->patchJson("/api/v1/routes/{$own->id}", ['web_root' => 'apps/docs/public'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.web_root_conflict');
        expect($own->refresh()->web_root)->toBeNull();
    });

    it('refuses to move a Route with a web root to a production Instance', function (): void {
        [$instance] = web_root_production($this->project);

        expect(fn () => RouteWebRoot::assertRetargetable($instance))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.web_root_unsupported'));
        RouteWebRoot::assertRetargetable($this->instance);
    });

    it('serves a production web root from its own pool and socket in the selected release', function (): void {
        [$instance] = web_root_production($this->project);
        web_root_route($instance, 'acme.example.com');
        web_root_route($instance, 'alias.example.com', 'public');
        web_root_route($instance, 'docs.example.com', 'apps/docs/public');
        web_root_route($instance, 'docs-raw.example.com', 'apps/docs');
        $suffix = substr(hash('sha256', 'apps/docs'), 0, 8);
        $sites = web_root_sites($instance->node);

        expect(RouteWebRoot::servedApplications($instance))->toBe([
            ['web_root' => 'apps/docs', 'directory' => 'apps/docs', 'suffix' => $suffix],
            ['web_root' => 'apps/docs/public', 'directory' => 'apps/docs', 'suffix' => $suffix],
        ])
            ->and($sites->get('acme.example.com')->socketPath())->toBe('/run/php/orbit-acme.sock')
            ->and($sites->get('alias.example.com')->socketPath())->toBe('/run/php/orbit-acme.sock')
            ->and($sites->get('docs.example.com')->socketPath())->toBe("/run/php/orbit-acme.{$suffix}.sock");
        expect(new DevelopmentCaddyConfigRenderer()->render(collect([$sites->get('docs.example.com')])))
            ->toContain('root * /home/orbit-acme/current/apps/docs/public')
            ->toContain("unix//run/php/orbit-acme.{$suffix}.sock");

        $identity = ProductionPhpRuntimeIdentity::from($instance);
        $renderer = new ProductionPhpRuntimeConfigRenderer;
        $pool = $renderer->render($identity, applications: RouteWebRoot::servedApplications($instance))->pool;
        $initial = $renderer->render($identity, initialRelease: true, applications: RouteWebRoot::servedApplications($instance))->pool;

        expect(substr_count($pool, "\n[orbit-"))->toBe(1)
            ->and($pool)->toStartWith($renderer->render($identity)->pool)
            ->toContain("[orbit-orbit-acme-{$suffix}]\n")
            ->toContain("listen = /run/php/orbit-acme.{$suffix}.sock\n")
            ->toContain("chdir = /home/orbit-acme/current/apps/docs\n")
            ->toContain("pm = ondemand\n")
            ->and($initial)->toContain("chdir = /home/orbit-acme/releases/initial/apps/docs\n")
            ->and(app(ProductionInstanceInspectionExpectationFactory::class)->make($instance)->runtimeConfiguration?->pool)->toBe($pool);
    });

    it('excludes withdrawn and failed production web roots from served and runtime inventories', function (string $status): void {
        [$instance] = web_root_production($this->project);
        $own = web_root_route($instance, 'acme.example.com');
        $docs = web_root_route($instance, 'docs.example.com', 'apps/docs/public');

        $docs->update([
            'status' => $status,
            'sites_published' => false,
            'failed_step' => $status === 'failed' ? 'production-runtime' : null,
            'error_code' => $status === 'failed' ? 'fixture.child_failed' : null,
        ]);

        expect(RouteWebRoot::servedApplications($instance))->toBe([])
            ->and(web_root_production_deploy_served($instance))->toBe([])
            ->and(app(ProductionInstanceInspectionExpectationFactory::class)->make($instance)->runtimeConfiguration?->pool)->not->toContain('apps/docs');
    })->with(['withdrawn' => ['retiring'], 'failed' => ['failed']]);

});

function web_root_node(string $name, string $address, bool $production): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => "{$name}.test",
        'public_ssh_host' => '192.0.2.'.substr($address, strrpos($address, '.') + 1),
        'wireguard_ip' => $address,
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($node, $production);

    return $node;
}

function web_root_instance(Project $project, Node $node): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $node->name,
        'checkout_path' => "/srv/orbit/apps/{$project->slug}/{$node->name}",
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'status' => InstanceState::Active,
    ]);
}

/** @return array{Instance} */
function web_root_production(Project $project): array
{
    $node = web_root_node('prod-web', '10.44.0.30', production: true);
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

    return [$instance->refresh()];
}

/** @return list<array{web_root: string, directory: string}> The served web roots that a later release preparation sends. */
function web_root_production_deploy_served(Instance $instance): array
{
    return array_map(static fn (array $application): array => ['web_root' => $application['web_root'], 'directory' => $application['directory']], RouteWebRoot::servedApplications($instance));
}

function web_root_route(Instance $instance, string $domain, ?string $webRoot = null): Route
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

/** @return Collection<string, DevelopmentSite> */
function web_root_sites(Node $node): Collection
{
    return new DevelopmentSiteRepository()->forNode($node)->keyBy('domain');
}
