<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentRuntimeRefresher;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CachedLaravelApplicationFixture;
use Tests\Support\FakeAgentCommandRunner;

beforeAll(function (): void {
    CachedLaravelApplicationFixture::prepare();
});

it('records a canonical dedicated PHP runtime identity without converting existing placements', function (): void {
    $project = Project::query()->create([
        'name' => 'Dedicated PHP',
        'slug' => 'dedicated-php',
        'repository_url' => 'https://example.test/dedicated-php.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'dedicated-php',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.214',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$project->id}",
        'production_user' => "orbit-app-{$project->id}",
        'production_home' => "/home/orbit-app-{$project->id}",
        'root' => 'public',
        'selected_php_version' => '8.5',
    ]);

    expect(Schema::hasColumns('instances', [
        'production_php_service',
        'production_php_pool',
        'production_php_socket',
    ]))
        ->toBeTrue()
        ->and($instance->production_php_service)
        ->toBeNull()
        ->and($instance->production_php_pool)
        ->toBeNull()
        ->and($instance->production_php_socket)
        ->toBeNull();

    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());

    expect($identity->service)
        ->toBe("orbit-orbit-app-{$project->id}-php8.5-fpm.service")
        ->and($identity->pool)
        ->toBe("orbit-orbit-app-{$project->id}")
        ->and($identity->socket)
        ->toBe("/run/php/orbit-app-{$project->id}.sock")
        ->and($identity->runtimeDirectory)
        ->toBe("/etc/orbit/php-fpm/orbit-app-{$project->id}")
        ->and(ProductionPhpRuntimeIdentity::from($instance->refresh()))
        ->toEqual($identity);
});

it('refuses a stored runtime association that differs from its production identity', function (): void {
    $project = Project::query()->create([
        'name' => 'Conflicting PHP',
        'slug' => 'conflicting-php',
        'repository_url' => 'https://example.test/conflicting-php.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'conflicting-php',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.215',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$project->id}",
        'production_user' => "orbit-app-{$project->id}",
        'production_home' => "/home/orbit-app-{$project->id}",
        'root' => 'public',
        'selected_php_version' => '8.5',
        'production_php_service' => 'php8.5-fpm.service',
        'production_php_pool' => "orbit-orbit-app-{$project->id}",
        'production_php_socket' => "/run/php/orbit-app-{$project->id}.sock",
    ]);

    expect(fn () => ProductionPhpRuntimeIdentity::from($instance))
        ->toThrow(ResourceOperationException::class);
});

it('renders generated identity separately from preserved local defaults', function (string $documentRoot, string $applicationDirectory, bool $initialRelease): void {
    $identity = new ProductionPhpRuntimeIdentity(
        user: 'orbit-app-9',
        home: '/home/orbit-app-9',
        version: '8.5',
        service: 'orbit-orbit-app-9-php8.5-fpm.service',
        pool: 'orbit-orbit-app-9',
        socket: '/run/php/orbit-app-9.sock',
        documentRoot: $documentRoot,
    );
    $renderer = new ProductionPhpRuntimeConfigRenderer;
    $rendered = $initialRelease ? $renderer->render($identity, initialRelease: true) : $renderer->render($identity);

    expect($rendered->main)
        ->toContain(
            'pid = /run/php/orbit-app-9.pid',
            'include = /etc/orbit/php-fpm/orbit-app-9/generated/pool.conf',
            'include = /etc/orbit/php-fpm/orbit-app-9/local.conf',
        )
        ->and($rendered->pool)
        ->toContain(
            '[orbit-orbit-app-9]',
            'user = orbit-app-9',
            'listen = /run/php/orbit-app-9.sock',
            'chdir = '.$applicationDirectory."\n",
            'env[HOME] = /home/orbit-app-9',
        )
        ->and($rendered->localDefaults)
        ->toContain(
            '[orbit-orbit-app-9]',
            'pm = ondemand',
        )
        ->not
        ->toContain('user =', 'listen =', 'chdir =', 'include =', 'opcache.')
        ->and($rendered->masterIni)
        ->toContain(
            'opcache.enable = On',
            'opcache.memory_consumption = 256',
            'opcache.interned_strings_buffer = 32',
            'opcache.max_accelerated_files = 65407',
            'opcache.validate_timestamps = 0',
            'opcache.jit = disable',
            'opcache.jit_buffer_size = 0',
        )
        ->and($rendered->unit)
        ->toContain(
            'Environment=PHP_INI_SCAN_DIR=/etc/php/8.5/fpm/conf.d:/etc/orbit/php-fpm/orbit-app-9/generated',
            'ExecStart=/usr/sbin/php-fpm8.5 --nodaemonize --fpm-config /etc/orbit/php-fpm/orbit-app-9/generated/php-fpm.conf',
            'PIDFile=/run/php/orbit-app-9.pid',
        );
})->with([
    'legacy public' => ['/home/orbit-app-9/public', '/home/orbit-app-9', false],
    'root public selected' => ['/home/orbit-app-9/current/public', '/home/orbit-app-9/current', false],
    'nested selected' => ['/home/orbit-app-9/current/server/web/public', '/home/orbit-app-9/current/server/web', false],
    'root public initial clone' => ['/home/orbit-app-9/current/public', '/home/orbit-app-9/releases/initial', true],
    'nested initial clone' => ['/home/orbit-app-9/current/server/web/public', '/home/orbit-app-9/releases/initial/server/web', true],
]);

it('keeps a dedicated production route in Caddy and out of shared FPM publication', function (): void {
    [$dedicated, $node] = orb214_runtime_instance();
    $dedicatedIdentity = ProductionPhpRuntimeIdentity::forProvisioning($dedicated, '8.5');
    $dedicated->update([
        ...$dedicatedIdentity->attributes(),
        'status' => InstanceState::SourceResolved,
    ]);
    $dedicatedRoute = Route::query()->create([
        'project_id' => $dedicated->project_id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'domain' => 'dedicated.example.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $dedicatedRoute->targets()->create(['instance_id' => $dedicated->id, 'position' => 0]);
    $dedicatedRoute->update(['status' => RouteStatus::Active]);

    $sharedApp = Project::query()->create([
        'name' => 'Shared PHP',
        'slug' => 'shared-php',
        'repository_url' => 'https://example.test/shared-php.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $shared = Instance::query()->create([
        'project_id' => $sharedApp->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$sharedApp->id}",
        'production_user' => "orbit-app-{$sharedApp->id}",
        'production_home' => "/home/orbit-app-{$sharedApp->id}",
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => InstanceState::SourceResolved,
    ]);
    $sharedRoute = Route::query()->create([
        'project_id' => $shared->project_id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'domain' => 'shared.example.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $sharedRoute->targets()->create(['instance_id' => $shared->id, 'position' => 0]);
    $sharedRoute->update(['status' => RouteStatus::Active]);

    $sites = new DevelopmentSiteRepository()->forNode($node);
    $caddy = new DevelopmentCaddyConfigRenderer()->render($sites);
    $sharedFpm = new DevelopmentPhpFpmConfigRenderer()->render(
        $sites->reject->usesDedicatedPhpRuntime()->values(),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );

    expect($sites)
        ->toHaveCount(2)
        ->and($caddy)
        ->toContain(
            "php_fastcgi unix/{$dedicatedIdentity->socket}",
            "php_fastcgi unix//run/php/orbit-app-instance-{$shared->id}.sock",
        )
        ->and($sharedFpm)
        ->toContain("[orbit-app-instance-{$shared->id}]")
        ->not->toContain(
            "[orbit-app-instance-{$dedicated->id}]",
            $dedicatedIdentity->socket,
        );
});

/** @return array{Instance, Node} */
it('prepares production config before resetting opcache and reports refresh skips and failures', function (string $receipt, int $exit, bool $php, bool|string $runtimeFails, string $expected, ?string $root): void {
    [$instance] = orb214_runtime_instance();
    $instance->project->update(['root' => 'apps/from-project/public']);
    $instance->update(['root' => $root]);
    if (! $php) {
        $instance->update(['selected_php_version' => null]);
    }
    $agents = new FakeAgentCommandRunner;
    $prepared = false;
    $agents->answer('instances.refresh-config-cache', function (array $input) use ($instance, $exit, $receipt, &$prepared): array {
        expect($input)->toBe([
            'user' => $instance->production_user,
            'home' => $instance->production_home,
            'version' => '8.5',
            'application' => $instance->production_home.'/current/'.($instance->root === null ? 'apps/from-project' : 'apps/site'),
        ]);
        if ($exit === 2) {
            throw new RuntimeException('SECRET SSH failure');
        }
        if ($exit !== 0) {
            throw new AgentCommandRefused('instances.refresh-config-cache', 'instances.release_step_failed', 'SECRET config output', ['step' => 'rebuild the configuration cache']);
        }
        $prepared = true;

        return ['outcome' => $receipt];
    });
    $runtime = Mockery::mock(ProductionPhpRuntimeManager::class);
    $shouldReset = $php && $exit === 0 && in_array($receipt, ['config_cached', 'config_uncached'], true);
    if ($shouldReset) {
        $call = $runtime->shouldReceive('refreshCache')->once()->withArgs(function (Instance $target) use ($instance, &$prepared): bool {
            expect($prepared)->toBeTrue();

            return $target->id === $instance->id;
        });
        if ($runtimeFails) {
            $call->andThrow($runtimeFails === 'unexpected'
                ? new RuntimeException('SECRET unexpected runtime output')
                : new RuntimeConvergenceException(step: 'reset', errorCode: 'failed', message: 'SECRET runtime output'));
        }
    } else {
        $runtime->shouldNotReceive('refreshCache');
    }
    $refresher = new RemoteInstanceEnvironmentRuntimeRefresher($agents, $runtime);
    $context = new InstanceEnvironmentContext(
        instanceId: $instance->id, projectId: $instance->project_id, nodeId: $instance->node_id,
        environment: 'production', path: $instance->production_home, executionUser: $instance->production_user,
        laravel: true, routeId: null, routeDomain: null, nodeStatus: 'active', node: $instance->node,
    );
    if (str_starts_with($expected, 'env.')) {
        try {
            $refresher->refresh($context);
            test()->fail('Refresh failure was swallowed.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe($expected)->and($exception->getMessage())->not->toContain('SECRET');
        }
    } else {
        expect($refresher->refresh($context))->toBe($expected);
    }
    expect($agents->calls)->toHaveCount($php ? 1 : 0);
})->with([
    'cached' => ['config_cached', 0, true, false, 'config_cache_and_opcache_refreshed'],
    'uncached' => ['config_uncached', 0, true, false, 'opcache_refreshed_config_uncached'],
    'no release' => ['no_current_release', 0, true, false, 'skipped_no_current_release'],
    'no PHP' => ['', 0, false, false, 'skipped_no_php_version'],
    'config failed' => ['SECRET config output', 1, true, false, 'env.config_cache_refresh_failed'],
    'unconfirmed' => ['', 0, true, false, 'env.config_cache_refresh_failed'],
    'config refused' => ['failed', 0, true, false, 'env.config_cache_refresh_failed'],
    'runtime failed' => ['config_cached', 0, true, true, 'env.php_runtime_refresh_failed'],
    'unexpected runtime failure' => ['config_cached', 0, true, 'unexpected', 'env.php_runtime_refresh_failed'],
    'unexpected remote failure' => ['', 2, true, false, 'env.config_cache_refresh_failed'],
])->with(['project root' => [null], 'instance root' => ['apps/site/public']]);

it('keeps copied Laravel caches and their application paths private', function (): void {
    $directory = sys_get_temp_dir().'/orbit-env-laravel-copies-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $mask = umask();
    try {
        $first = CachedLaravelApplicationFixture::install($directory.'/first', 'null', 'vendor');
        $second = CachedLaravelApplicationFixture::install($directory.'/second', 'null', 'dependencies');
        expect((require $first.'/bootstrap/cache/config.php')['filesystems']['disks']['local']['root'])
            ->toBe($first.'/storage/app/private');
        $secondHashes = [];
        $secondModes = [];
        foreach ($files->files($second.'/bootstrap/cache') as $cacheFile) {
            $secondHashes[$cacheFile->getFilename()] = hash_file('sha256', $cacheFile->getPathname());
            $secondModes[$cacheFile->getFilename()] = $cacheFile->getPerms() & 0o777;
        }
        foreach ($files->files($first.'/bootstrap/cache') as $cacheFile) {
            $files->put($cacheFile->getPathname(), '<?php return ["changed" => true];');
            chmod($cacheFile->getPathname(), 0400);
        }
        $files->put($directory.'/first/.env', "QUEUE_CONNECTION=redis\n");
        umask(0077);
        $third = CachedLaravelApplicationFixture::install($directory.'/third', 'null', '../../vendor');
        umask($mask);
        $secondConfiguration = require $second.'/bootstrap/cache/config.php';
        $thirdConfiguration = require $third.'/bootstrap/cache/config.php';
        expect(array_unique(array_map(static fn (string $application): int|false => fileinode($application.'/bootstrap/cache/config.php'), [$first, $second, $third])))
            ->toHaveCount(3)
            ->and($secondConfiguration['queue']['default'])->toBe('database')
            ->and($thirdConfiguration['queue']['default'])->toBe('database')
            ->and($secondConfiguration['filesystems']['disks']['local']['root'])->toBe($second.'/storage/app/private')
            ->and($thirdConfiguration['filesystems']['disks']['local']['root'])->toBe($third.'/storage/app/private')
            ->and(readlink($first.'/.env'))->toBe($directory.'/first/.env')
            ->and(readlink($second.'/.env'))->toBe($directory.'/second/.env')
            ->and(readlink($third.'/.env'))->toBe($directory.'/third/.env')
            ->and($files->get($second.'/.env'))->toBe("QUEUE_CONNECTION=database\n")
            ->and($files->get($third.'/.env'))->toBe("QUEUE_CONNECTION=database\n");
        foreach ($secondHashes as $name => $hash) {
            expect(hash_file('sha256', $second.'/bootstrap/cache/'.$name))->toBe($hash)
                ->and(fileperms($second.'/bootstrap/cache/'.$name) & 0o777)->toBe($secondModes[$name])
                ->and(fileperms($third.'/bootstrap/cache/'.$name) & 0o777)->toBe($secondModes[$name]);
        }
    } finally {
        umask($mask);
        $files->deleteDirectory($directory);
    }
});

function orb214_runtime_instance(): array
{
    $project = Project::query()->create([
        'name' => 'Runtime fixture',
        'slug' => 'runtime-fixture',
        'repository_url' => 'https://example.test/runtime-fixture.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'runtime-fixture',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.216',
        'wireguard_ip' => '10.44.0.214',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $user = "orbit-app-{$project->id}";

    return [
        Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'primary',
            'environment' => 'production',
            'checkout_path' => "/home/{$user}",
            'production_user' => $user,
            'production_home' => "/home/{$user}",
            'root' => 'public',
            'selected_php_version' => '8.5',
        ]),
        $node,
    ];
}
