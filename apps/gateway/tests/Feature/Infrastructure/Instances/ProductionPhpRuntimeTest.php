<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramFailure;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentRuntimeRefresher;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\CachedLaravelApplicationFixture;
use Tests\Support\StandardLibraryPython;

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
    $programs = Mockery::mock(RemoteProgramRunner::class);
    $prepared = false;
    if ($php) {
        $programs->shouldReceive('run')->once()->withArgs(fn (Node $node, RemoteProgram $program, array $input): bool => $node->id === $instance->node_id && $program->name === 'instances.refresh-environment-config'
            && $input === [
                'user' => $instance->production_user,
                'home' => $instance->production_home,
                'version' => '8.5',
                'application' => $instance->production_home.'/current/'.($instance->root === null ? 'apps/from-project' : 'apps/site'),
            ])->andReturnUsing(function () use ($exit, $receipt, &$prepared): ProgramResult {
                if ($exit === 2) {
                    throw new RuntimeException('SECRET SSH failure');
                }
                if ($exit !== 0) {
                    throw new RemoteProgramFailed('instances.refresh-environment-config', RemoteProgramFailure::NoResult);
                }
                $prepared = true;

                return new ProgramResult($receipt, [], '');
            });
    } else {
        $programs->shouldNotReceive('run');
    }
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
    $refresher = new RemoteInstanceEnvironmentRuntimeRefresher($programs, new RemoteProgramCatalog, $runtime);
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

it('runs config cache as the production user only for an existing cached current release', function (bool $current, bool $cached, int $fail, string $suffix): void {
    $directory = sys_get_temp_dir().'/orbit-env-refresh-'.bin2hex(random_bytes(8));
    $application = $directory.'/release'.$suffix;
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory.'/bin');
    $files->ensureDirectoryExists($application.'/bootstrap/cache');
    if ($current) {
        symlink($directory.'/release', $directory.'/current');
    }
    if ($cached) {
        $files->put($application.'/bootstrap/cache/config.php', 'old config');
    }
    $files->put($directory.'/bin/sudo', "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> \"\$TRACE\"\n[[ \$1 == -u && \$2 == production-user && \$3 == -H ]] || exit 99\nshift 3\nexec \"\$@\"\n");
    $files->put($directory.'/bin/php8.5', '#!/bin/bash'."\n".'[[ $PWD == "$HOME_FIXTURE/current$APPLICATION_SUFFIX" || $PWD == "$HOME_FIXTURE/release$APPLICATION_SUFFIX" ]] || exit 98'."\n"
        .'if [[ $* == "artisan --version --no-interaction" ]]; then exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('exit(is_array(require $argv[1]) ? 0 : 1);').' "$APP_CONFIG_CACHE"; fi'."\n"
        .'[[ $* == "artisan config:cache" && $APP_CONFIG_CACHE != "$PWD/bootstrap/cache/config.php" ]] || exit 97'."\n"
        .'[[ $FAIL_CONFIG != 3 ]] || exit 0'."\n"
        .'printf "<?php return [123];" > "$APP_CONFIG_CACHE"'."\n"
        .'[[ $FAIL_CONFIG != 1 ]] || exit 96'."\n"
        .'[[ $FAIL_CONFIG != 2 ]] || printf "<?php return false;" > "$APP_CONFIG_CACHE"'."\n"
        .'exit 0'."\n");
    chmod($directory.'/bin/sudo', 0700);
    chmod($directory.'/bin/php8.5', 0700);
    $catalog = new RemoteProgramCatalog;
    $program = $catalog->program('instances.refresh-environment-config');
    $process = new Process([StandardLibraryPython::executable(), '-I', '-c', $catalog->bootstrap(), $program->name], env: [
        'PATH' => $directory.'/bin:'.getenv('PATH'), 'TRACE' => $directory.'/trace', 'HOME_FIXTURE' => $directory, 'APPLICATION_SUFFIX' => $suffix, 'FAIL_CONFIG' => (string) $fail,
    ]);
    $process->setInput($program->source."\0".json_encode(['user' => 'production-user', 'home' => $directory, 'version' => '8.5', 'application' => $directory.'/current'.$suffix], JSON_THROW_ON_ERROR));
    try {
        $process->run();
        expect($process->isSuccessful())->toBeTrue()->and($process->getErrorOutput())->toBe('');
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($result['outcome'])->toBe(match (true) {
            ! $current => 'no_current_release',
            $cached && $fail => 'failed',
            $cached => 'config_cached',
            default => 'config_uncached',
        });
        expect($files->get($directory.'/trace'))->toContain('-u production-user -H test');
        if ($current && $cached && ! $fail) {
            expect($files->get($application.'/bootstrap/cache/config.php'))->toBe('<?php return [123];');
            expect(glob($application.'/bootstrap/cache/.orbit-config-*'))->toBe([]);
        } elseif ($cached) {
            expect($files->get($application.'/bootstrap/cache/config.php'))->toBe('old config');
        } else {
            expect($files->exists($application.'/bootstrap/cache/config.php'))->toBeFalse();
        }
    } finally {
        $files->deleteDirectory($directory);
    }
})->with([[true, true, 0], [true, false, 0], [false, false, 0], [true, true, 1], [true, true, 2], [true, true, 3]])->with(['release root' => [''], 'nested application' => ['/apps/site']]);

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

it('rebuilds real Laravel cached configuration in a nested application from the changed environment', function (string $cachedValue, string $vendorDirectory): void {
    $directory = sys_get_temp_dir().'/orbit-env-laravel-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    try {
        $application = CachedLaravelApplicationFixture::install($directory, $cachedValue, $vendorDirectory);
        $environment = ['QUEUE_CONNECTION' => false, 'APP_CONFIG_CACHE' => false];
        $cache = $application.'/bootstrap/cache/config.php';
        $initial = (require $cache)['queue'];
        expect($initial['default'])->toBe('database');
        $files->put($directory.'/.env', "QUEUE_CONNECTION=redis\n");
        $catalog = new RemoteProgramCatalog;
        $program = $catalog->program('instances.refresh-environment-config');
        $process = new Process([StandardLibraryPython::executable(), '-I', '-c', $catalog->bootstrap(), $program->name], env: [
            ...$environment, 'PATH' => $directory.'/bin:'.getenv('PATH'),
        ]);
        $process->setInput($program->source."\0".json_encode([
            'user' => 'production-user', 'home' => $directory, 'version' => '8.5', 'application' => $directory.'/current/apps/site',
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();
        $refreshed = require $cache;
        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome'])->toBe('config_cached')
            ->and($process->getErrorOutput())->toBe('')
            ->and($refreshed['queue']['default'])->toBe('redis')
            ->and($refreshed['queue']['typed'])->toEqual($initial['typed'])
            ->and($refreshed['filesystems']['disks']['local']['root'])->toBe($application.'/storage/app/private');
    } finally {
        $files->deleteDirectory($directory);
    }
})->with(CachedLaravelApplicationFixture::cachedValues())->with([
    'application vendor' => ['vendor'],
    'custom Composer vendor-dir' => ['dependencies'],
    'shared repository autoloader' => ['../../vendor'],
]);

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

function orb214_app_prod_ssh(AppDevFakeSshExecutor $ssh): ProductionSshExecutor
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    return new ProductionSshExecutor($ssh, $keys, $knownHosts);
}
