<?php

declare(strict_types=1);

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\PhpBuildAnswers;

/*
 * Production Instances that existed before Routes with a web root reached production have no such
 * Route. The fixture records their current production release layout: the workload Caddy
 * file, every PHP-FPM file, the release `.env` link, and each command, with its arguments, standard
 * input, and protected input, that converges, monitors, removes, or refreshes the runtime on the Node, and the agent
 * command and input that deploys and activates a release. Shared paths update only the deployment command baseline.
 */
it('renders existing production Instances against the release layout baseline', function (): void {
    $node = Node::query()->create([
        'name' => 'prod-equivalence',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'prod-equivalence.test',
        'public_ssh_host' => '192.0.2.71',
        'wireguard_ip' => '10.44.0.71',
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($node, true);
    $instances = [
        prod_equivalence_instance($node, 'shop', 'public', 'shop.example.com'),
        prod_equivalence_instance($node, 'site', 'apps/site/public', 'site.example.com'),
    ];

    $fixture = __DIR__.'/../../../Fixtures/ProductionWebRootEquivalence/existing-production-instances.json';
    $rendered = json_encode(prod_equivalence_render($node, $instances), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    expect($rendered)->toBe(file_get_contents($fixture));
});

function prod_equivalence_instance(Node $node, string $slug, string $root, string $domain): Instance
{
    $project = Project::query()->create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'type' => 'laravel-app',
        'repository_url' => "https://example.test/{$slug}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $user = "orbit-{$slug}";
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => "/home/{$user}/releases/initial",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'root' => $root,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance->refresh(), '8.4')->attributes());
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => $domain,
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $instance->refresh();
}

/**
 * @param  list<Instance>  $instances
 * @return array<string, mixed>
 */
function prod_equivalence_render(Node $node, array $instances): array
{
    $rendered = ['caddy' => new DevelopmentCaddyConfigRenderer()->render(new DevelopmentSiteRepository()->forNode($node))];
    $renderer = new ProductionPhpRuntimeConfigRenderer;

    foreach ($instances as $instance) {
        $identity = ProductionPhpRuntimeIdentity::from($instance);
        $files = ['marker' => $identity->marker()];

        foreach ([false, true] as $metrics) {
            foreach ([false, true] as $initialRelease) {
                $configuration = $renderer->render($identity, $metrics, $initialRelease);
                $key = ($metrics ? 'metrics' : 'plain').'-'.($initialRelease ? 'initial' : 'current');
                $files[$key] = [
                    'main' => $configuration->main,
                    'pool' => $configuration->pool,
                    'local_defaults' => $configuration->localDefaults,
                    'master_ini' => $configuration->masterIni,
                    'unit' => $configuration->unit,
                ];
            }
        }

        $agents = new FakeAgentCommandRunner()
            ->answer('instance.prepare-release', ['name' => '20261009-a1', 'commit' => str_repeat('b', 40)])
            ->answer('instance.activate-release', ['name' => '20261009-a1', 'commit' => str_repeat('b', 40)]);
        $deployment = new RemoteProductionDeployment($agents, app(RepositoryReadAccess::class), app(PrepareVpEnvironmentAction::class), static fn (): string => '20261009-a1');
        $release = $deployment->prepare($instance, 'main');
        $deployment->activate($instance, new DeploymentRelease($release->name, $release->path, $release->commit));
        // Both the PHP build and runtime use typed agent commands.
        $runtime = static function (Closure $operation): array {
            $agents = new FakeAgentCommandRunner()
                ->answer('php.install-build', PhpBuildAnswers::result())->answer('php.converge-production-runtime', ['published' => true])
                ->answer('php.remove-production-runtime', ['removed' => true])
                ->answer('php.refresh-production-cache', ['complete' => true]);
            $operation(new RemoteProductionPhpRuntimeManager(new ProductionPhpRuntimeConfigRenderer, $agents));

            return array_map(static fn (array $call): array => ['command' => $call['command'], 'input' => $call['command'] === 'php.install-build' ? null : $call['input']], $agents->calls);
        };

        $rendered[$instance->production_user] = [
            'php_fpm' => $files,
            'release_environment_link' => ProductionApplicationPaths::render('__APPLICATION_SUFFIX__|__ENVIRONMENT_TARGET__|__ENVIRONMENT_PATH__', $instance->root),
            // The agent commands and their typed input; the agent's Rust tests prove what each one does.
            'deployment_commands' => array_map(static fn (array $call): array => [
                'command' => $call['command'],
                'input' => array_map(static fn (mixed $value): mixed => $value instanceof GitReadEnvironment ? $value->variables : $value, $call['input']),
            ], $agents->calls),
            'php_fpm_converge_commands' => $runtime(static fn (RemoteProductionPhpRuntimeManager $manager) => $manager->converge($instance)),
            'php_fpm_monitor_commands' => $runtime(static fn (RemoteProductionPhpRuntimeManager $manager) => $manager->convergeMonitoring($instance, true)),
            'php_fpm_remove_commands' => $runtime(static fn (RemoteProductionPhpRuntimeManager $manager) => $manager->remove($instance)),
            'php_fpm_refresh_commands' => $runtime(static fn (RemoteProductionPhpRuntimeManager $manager) => $manager->refreshCache($instance)),
        ];
    }

    return $rendered;
}
