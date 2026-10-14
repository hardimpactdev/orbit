<?php

declare(strict_types=1);

use App\Actions\Instances\Dependencies\ReadInstanceDependencyScanAction;
use App\Actions\Instances\Dependencies\ScanInstanceDependenciesAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Instances\Dependencies\DependencyInventoryState;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\Instances\DependencyFilesProgram;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\InstanceDependencyScanAttempt;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

function dependency_scan_instance(): Instance
{
    $project = Project::query()->create(['slug' => 'dependency-scan', 'name' => 'Dependency scan', 'repository_url' => 'https://example.test/scan.git']);
    $node = Node::query()->create(['name' => 'dependency-scan', 'public_ssh_host' => '192.0.2.180', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit', 'status' => 'active']);
    orbit_test_set_app_placement_role($node, false);

    return $project->instances()->create([
        'node_id' => $node->id, 'name' => 'scan', 'environment' => 'development',
        'checkout_path' => '/home/orbit/project', 'source_layout' => 'checkout', 'status' => 'active',
    ]);
}

/** @return array<string, mixed> */
function dependency_scan_receipt(string $manager = 'npm'): array
{
    $files = array_fill_keys(DependencyFilesProgram::FILES, ['content' => null, 'hash' => null, 'error' => null]);
    $inputs = [
        'composer.json' => 'Composer/composer2-manifest.json', 'composer.lock' => 'Composer/composer2-lock.json',
        ...match ($manager) {
            'npm' => ['package.json' => 'Npm/manifest.json', 'package-lock.json' => 'Npm/package-lock-v3.json'],
            'pnpm' => ['package.json' => 'Pnpm/manifest.json', 'pnpm-lock.yaml' => 'Pnpm/pnpm-lock.yaml'],
            'bun' => ['package.json' => 'Bun/manifest.json', 'bun.lock' => 'Bun/bun.lock'],
        },
    ];
    foreach ($inputs as $name => $path) {
        $content = file_get_contents(base_path('tests/Fixtures/Dependencies/'.$path));
        $files[$name] = ['content' => base64_encode($content), 'hash' => hash('sha256', $content), 'error' => null];
    }

    return ['root' => '/home/orbit/project', 'reference' => null, 'identity' => str_repeat('a', 64), 'files' => $files];
}

function dependency_scan_remote(Closure $response): void
{
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function () use ($response): CommandResult {
        return new CommandResult(0, json_encode($response(), JSON_THROW_ON_ERROR), '', 1, false);
    });
}

/** Run the pinned collector locally instead of opening an SSH connection. */
function dependency_scan_local_source(Instance $instance): string
{
    $path = sys_get_temp_dir().'/orbit-monorepo-scan-'.Str::uuid();
    mkdir($path, 0700);
    $instance->update(['checkout_path' => $path]);
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function ($connection, $command): CommandResult {
        $process = new Process($command->arguments);
        $process->setInput($command->input);
        $process->mustRun();

        return new CommandResult(0, $process->getOutput(), $process->getErrorOutput(), 1, false);
    });

    return $path;
}

function dependency_scan_write_fixture(string $path, string $ecosystem): void
{
    new Filesystem()->ensureDirectoryExists($path);
    $inputs = $ecosystem === 'composer'
        ? ['composer.json' => 'Composer/composer2-manifest.json', 'composer.lock' => 'Composer/composer2-lock.json']
        : ['package.json' => 'Npm/manifest.json', 'package-lock.json' => 'Npm/package-lock-v3.json'];
    foreach ($inputs as $name => $fixture) {
        copy(base_path('tests/Fixtures/Dependencies/'.$fixture), $path.'/'.$name);
    }
}

describe('coordinated instance dependency scans', function (): void {
    it('publishes both real parser graphs and replaces usage idempotently', function (string $manager): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt($manager);
        dependency_scan_remote(fn (): array => $receipt);
        $scan = app(ScanInstanceDependenciesAction::class);
        $first = $scan->execute($instance);
        $count = $instance->dependencyObservations()->withCount('resolutions')->get()->sum('resolutions_count');

        $second = $scan->execute($instance);

        expect($first->succeeded())->toBeTrue();
        expect($second->composer->snapshot->graph)->toEqual($first->composer->snapshot->graph);
        expect($second->javascript->snapshot->graph)->toEqual($first->javascript->snapshot->graph);
        expect($count)->toBeGreaterThan(5);
        $this->assertDatabaseCount('instance_dependency_observations', 2);
        $this->assertDatabaseCount('instance_dependency_resolutions', $count);
        $this->assertDatabaseCount('instance_dependency_scan_attempts', 4);
    })->with(['npm', 'pnpm', 'bun']);

    it('clears usage only after confirmed absence and preserves the other ecosystem', function (): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt();
        dependency_scan_remote(function () use (&$receipt): array {
            return $receipt;
        });
        $scan = app(ScanInstanceDependenciesAction::class);
        $first = $scan->execute($instance);
        foreach (['package.json', 'package-lock.json'] as $name) {
            $receipt['files'][$name] = ['content' => null, 'hash' => null, 'error' => null];
        }

        $result = $scan->execute($instance);

        expect($result->javascript->state())->toBe(DependencyInventoryState::Absent);
        expect($result->composer->snapshot->graph)->toEqual($first->composer->snapshot->graph);
        expect($instance->dependencyObservations()->where('ecosystem', 'npm')->sole()->resolutions()->count())->toBe(0);
    });

    it('retains the previous graph after an ecosystem selection or parsing failure', function (string $failure, string $code): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt();
        dependency_scan_remote(function () use (&$receipt): array {
            return $receipt;
        });
        $scan = app(ScanInstanceDependenciesAction::class);
        $first = $scan->execute($instance);
        $receipt['files']['package-lock.json'] = match ($failure) {
            'unreadable' => ['content' => null, 'hash' => null, 'error' => $code],
            'missing' => ['content' => null, 'hash' => null, 'error' => null],
            default => ['content' => base64_encode('{}'), 'hash' => hash('sha256', '{}'), 'error' => null],
        };

        $result = $scan->execute($instance);

        expect($result->composer->succeeded())->toBeTrue();
        expect($result->javascript->errorCode)->toBe($code);
        expect($result->javascript->state())->toBe(DependencyInventoryState::Stale);
        expect($result->javascript->snapshot)->toEqual($first->javascript->snapshot);
    })->with([
        ['unreadable', 'dependencies.unreadable_source'], ['missing', 'dependencies.incomplete_source'],
        ['parse', 'dependencies.unsupported_format'],
    ]);

    it('retains both snapshots after initial or final collection fails', function (int $failedCall): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt();
        $calls = 0;
        dependency_scan_remote(function () use (&$calls, $receipt, $failedCall): array {
            $calls++;

            return $calls === $failedCall ? ['error' => 'dependencies.unreadable_source'] : $receipt;
        });
        $scan = app(ScanInstanceDependenciesAction::class);
        $first = $scan->execute($instance);

        $result = $scan->execute($instance);

        expect($result->composer->errorCode)->toBe('dependencies.unreadable_source');
        expect($result->javascript->errorCode)->toBe('dependencies.unreadable_source');
        expect($result->composer->snapshot)->toEqual($first->composer->snapshot);
        expect($result->javascript->snapshot)->toEqual($first->javascript->snapshot);
    })->with([3, 4]);

    it('retains usage when apparent absence cannot be confirmed', function (): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt();
        $calls = 0;
        dependency_scan_remote(function () use (&$calls, $receipt): array {
            $calls++;
            if ($calls === 3) {
                $receipt['files'] = array_fill_keys(DependencyFilesProgram::FILES, ['content' => null, 'hash' => null, 'error' => null]);
            }

            return $receipt;
        });
        $scan = app(ScanInstanceDependenciesAction::class);
        $first = $scan->execute($instance);

        $result = $scan->execute($instance);

        expect($result->composer->errorCode)->toBe('dependencies.source_changed');
        expect($result->javascript->errorCode)->toBe('dependencies.source_changed');
        expect($result->composer->snapshot)->toEqual($first->composer->snapshot);
        expect($result->javascript->snapshot)->toEqual($first->javascript->snapshot);
    });

    it('replaces a changed lock version without retaining old usage', function (): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt();
        $receipt['files'] = array_fill_keys(DependencyFilesProgram::FILES, ['content' => null, 'hash' => null, 'error' => null]);
        $manifest = '{"dependencies":{"widget":"*"}}';
        $receipt['files']['package.json'] = ['content' => base64_encode($manifest), 'hash' => hash('sha256', $manifest), 'error' => null];
        $version = '1.0.0';
        dependency_scan_remote(function () use ($receipt, &$version): array {
            $lock = json_encode(['lockfileVersion' => 3, 'packages' => ['' => ['dependencies' => ['widget' => '*']], 'node_modules/widget' => ['version' => $version]]], JSON_THROW_ON_ERROR);
            $receipt['files']['package-lock.json'] = ['content' => base64_encode($lock), 'hash' => hash('sha256', $lock), 'error' => null];

            return $receipt;
        });
        $scan = app(ScanInstanceDependenciesAction::class);
        $scan->execute($instance);
        $version = '1.1.0';

        $result = $scan->execute($instance);

        expect($result->succeeded())->toBeTrue();
        expect($result->javascript->snapshot->graph->resolutions[0]->version)->toBe('1.1.0');
        $this->assertDatabaseCount('instance_dependency_resolutions', 1);
        $this->assertDatabaseMissing('instance_dependency_resolutions', ['version' => '1.0.0']);
    });

    it('keeps a first collection failure unknown', function (): void {
        $instance = dependency_scan_instance();
        dependency_scan_remote(fn (): array => ['error' => 'dependencies.source_unavailable']);

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->composer->state())->toBe(DependencyInventoryState::Unknown);
        expect($result->javascript->state())->toBe(DependencyInventoryState::Unknown);
        $this->assertDatabaseCount('instance_dependency_observations', 0);
        $this->assertDatabaseCount('instance_dependency_scan_attempts', 2);
    });

    it('rejects changed release and directory receipts instead of publishing mixed source', function (bool $production): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt();
        if ($production) {
            $instance->node->roles()->where('role', 'app-dev')->delete();
            $instance->node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
            $instance->update(['production_user' => 'app_sample', 'production_home' => '/home/app_sample']);
            $receipt['root'] = '/home/app_sample/releases/first';
            $receipt['reference'] = 'first';
        }
        $calls = 0;
        dependency_scan_remote(function () use (&$calls, $receipt, $production): array {
            if (++$calls === 2) {
                $receipt['identity'] = str_repeat('b', 64);
                if ($production) {
                    $receipt['root'] = '/home/app_sample/releases/second';
                    $receipt['reference'] = 'second';
                }
            }

            return $receipt;
        });

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->composer->errorCode)->toBe('dependencies.source_changed');
        expect($result->javascript->errorCode)->toBe('dependencies.source_changed');
        $this->assertDatabaseCount('instance_dependency_observations', 0);
    })->with([false, true]);

    it('rejects a changed database source at the publication boundary', function (string $field, mixed $value): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt();
        $calls = 0;
        dependency_scan_remote(function () use (&$calls, $receipt, $instance, $field, $value): array {
            if (++$calls === 2) {
                $instance->update([$field => $value]);
            }

            return $receipt;
        });

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->succeeded())->toBeFalse();
        $this->assertDatabaseCount('instance_dependency_observations', 0);
    })->with([
        ['checkout_path', '/home/orbit/other'], ['status', 'reserved'],
        ['root', 'apps/other/public'], ['source_is_laravel', true],
    ]);

    it('does not resurrect usage when removal deletes the instance during collection', function (): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt();
        $calls = 0;
        dependency_scan_remote(function () use (&$calls, $receipt, $instance): array {
            if (++$calls === 2) {
                $instance->delete();
            }

            return $receipt;
        });

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->composer->errorCode)->toBe('dependencies.instance_unavailable');
        expect($result->javascript->errorCode)->toBe('dependencies.instance_unavailable');
        $this->assertDatabaseCount('instance_dependency_observations', 0);
        $this->assertDatabaseCount('instance_dependency_scan_attempts', 0);
    });

    it('refuses unavailable instances before SSH', function (bool $deleted): void {
        $instance = dependency_scan_instance();
        if ($deleted) {
            $instance->delete();
        } else {
            $instance->update(['status' => 'reserved']);
        }
        mock(SshExecutor::class)->shouldNotReceive('execute');

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->composer->errorCode)->toBe('dependencies.instance_unavailable');
        expect($result->javascript->errorCode)->toBe('dependencies.instance_unavailable');
        $this->assertDatabaseCount('instance_dependency_observations', 0);
    })->with([false, true]);

    it('holds the shared lifecycle and development source locks through both inspections and publication', function (): void {
        $instance = dependency_scan_instance();
        $directory = sys_get_temp_dir().'/orbit-scan-lock-'.Str::uuid();
        $owner = new NativeInstanceEnvironmentOperationLock($directory, new CommandDeadline);
        $source = new NativeAppDevSourceOperationLock($directory);
        app()->instance(InstanceEnvironmentOperationLock::class, $owner);
        app()->instance(AppDevSourceOperationLock::class, $source);
        $receipt = dependency_scan_receipt();
        $check = function () use ($directory, $instance): void {
            foreach (["app-instance-{$instance->id}", "node-{$instance->node_id}"] as $name) {
                $handle = fopen($directory.'/'.$name.'.lock', 'c+');
                expect(flock($handle, LOCK_EX | LOCK_NB))->toBeFalse();
                fclose($handle);
            }
        };
        $calls = 0;
        dependency_scan_remote(function () use ($check, &$calls, $receipt): array {
            $check();
            $calls++;

            return $receipt;
        });
        InstanceDependencyScanAttempt::creating($check);
        try {
            $result = $owner->run([$instance->id], fn () => $source->synchronized($instance->node_id, fn () => app(ScanInstanceDependenciesAction::class)->execute($instance)));
            expect($result->succeeded())->toBeTrue();
            expect($calls)->toBe(2);
        } finally {
            InstanceDependencyScanAttempt::flushEventListeners();
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('discovers only direct web apps and packages and preserves separate inventories', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo']);
        $path = dependency_scan_local_source($instance);
        dependency_scan_write_fixture($path.'/apps/api', 'composer');
        dependency_scan_write_fixture($path.'/packages/widget', 'npm');
        dependency_scan_write_fixture($path.'/packages/other', 'npm');
        $otherLock = $path.'/packages/other/package-lock.json';
        file_put_contents($otherLock, str_replace('"version": "1.1.0"', '"version": "1.2.0"', file_get_contents($otherLock)));
        new Filesystem()->ensureDirectoryExists($path.'/apps/native');
        file_put_contents($path.'/apps/native/Cargo.toml', '[package]');
        dependency_scan_write_fixture($path.'/apps/native/nested', 'composer');
        dependency_scan_write_fixture($path.'/elsewhere/api', 'composer');
        file_put_contents($path.'/package.json', '{"workspaces":["apps/*"]}');
        try {
            $scan = app(ScanInstanceDependenciesAction::class);
            $first = $scan->execute($instance);
            $lock = $path.'/packages/other/package-lock.json';
            file_put_contents($lock, '{}');

            $second = $scan->execute($instance);

            expect(array_keys($first->subProjects))->toBe(['apps/api', 'packages/other', 'packages/widget']);
            expect($first->succeeded())->toBeTrue();
            expect($first->composer)->toBeNull();
            expect(collect($first->subProjects['packages/widget']->javascript->snapshot->graph->resolutions)->firstWhere('id', 'node_modules/app-one')->version)->toBe('1.1.0');
            expect(collect($first->subProjects['packages/other']->javascript->snapshot->graph->resolutions)->firstWhere('id', 'node_modules/app-one')->version)->toBe('1.2.0');
            expect($second->succeeded())->toBeFalse();
            expect($second->subProjects['apps/api']->composer->succeeded())->toBeTrue();
            expect($second->subProjects['packages/widget']->javascript->succeeded())->toBeTrue();
            expect($second->subProjects['packages/other']->javascript->errorCode)->toBe('dependencies.unsupported_format');
            expect($second->subProjects['packages/other']->javascript->snapshot)->toEqual($first->subProjects['packages/other']->javascript->snapshot);
            expect(app(ReadInstanceDependencyScanAction::class)->subProjects($instance->id))->toEqual($second->subProjects);
            $this->assertDatabaseCount('instance_dependency_observations', 6);
            $this->assertDatabaseCount('instance_dependency_scan_attempts', 12);
            expect(file_get_contents($path.'/package.json'))->toBe('{"workspaces":["apps/*"]}');
        } finally {
            new Filesystem()->deleteDirectory($path);
        }
    });

    it('discovers repository siblings when a monorepo has a nested Laravel application root', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo', 'root' => 'apps/gateway/public']);
        $instance->update(['source_is_laravel' => true]);
        $path = dependency_scan_local_source($instance);
        dependency_scan_write_fixture($path.'/apps/gateway', 'composer');
        dependency_scan_write_fixture($path.'/apps/sibling', 'composer');
        dependency_scan_write_fixture($path.'/packages/widget', 'npm');
        try {
            $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

            expect($result->succeeded())->toBeTrue();
            expect(array_keys($result->subProjects))->toBe(['apps/gateway', 'apps/sibling', 'packages/widget']);
            expect($result->subProjects['apps/gateway']->composer->snapshot->source->projectRoot)->toBe($path.'/apps/gateway');
            expect($result->subProjects['apps/sibling']->composer->snapshot->source->projectRoot)->toBe($path.'/apps/sibling');
            expect($result->subProjects['packages/widget']->javascript->snapshot->source->projectRoot)->toBe($path.'/packages/widget');
            expect(array_keys(app(ReadInstanceDependencyScanAction::class)->subProjects($instance->id)))->toBe(['apps/gateway', 'apps/sibling', 'packages/widget']);
            $this->assertDatabaseCount('instance_dependency_observations', 6);
            $this->assertDatabaseMissing('instance_dependency_scan_attempts', ['instance_id' => $instance->id, 'sub_project' => '']);
        } finally {
            new Filesystem()->deleteDirectory($path);
        }
    });

    it('falls back to the nested Laravel application when both top-level discovery directories are missing', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo', 'root' => 'server/web/public']);
        $instance->update(['source_is_laravel' => true]);
        $path = dependency_scan_local_source($instance);
        dependency_scan_write_fixture($path, 'npm');
        dependency_scan_write_fixture($path.'/server/web', 'composer');
        dependency_scan_write_fixture($path.'/server/web/apps/ignored', 'npm');
        try {
            $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

            expect($result->succeeded())->toBeTrue();
            expect($result->subProjects)->toBeNull();
            expect($result->composer->snapshot->source->projectRoot)->toBe($path.'/server/web');
            expect($result->javascript->state())->toBe(DependencyInventoryState::Absent);
            $this->assertDatabaseCount('instance_dependency_observations', 2);
            $this->assertDatabaseMissing('instance_dependency_scan_attempts', ['instance_id' => $instance->id, 'sub_project' => 'apps/ignored']);
        } finally {
            new Filesystem()->deleteDirectory($path);
        }
    });

    it('keeps single-directory scanning without discovery for other types or missing monorepo directories', function (string $type): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => $type]);
        $path = dependency_scan_local_source($instance);
        dependency_scan_write_fixture($path, 'composer');
        if ($type !== 'monorepo') {
            dependency_scan_write_fixture($path.'/apps/ignored', 'npm');
        }
        try {
            $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

            expect($result->succeeded())->toBeTrue();
            expect($result->subProjects)->toBeNull();
            expect($result->composer->snapshot->source->projectRoot)->toBe($path);
            $this->assertDatabaseCount('instance_dependency_observations', 2);
        } finally {
            new Filesystem()->deleteDirectory($path);
        }
    })->with(['monorepo', 'node-package']);

    it('isolates unsafe files to their sub-project and leaves valid siblings readable', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo']);
        $path = dependency_scan_local_source($instance);
        dependency_scan_write_fixture($path.'/apps/bad', 'composer');
        dependency_scan_write_fixture($path.'/packages/good', 'npm');
        unlink($path.'/apps/bad/composer.lock');
        symlink($path.'/packages/good/package-lock.json', $path.'/apps/bad/composer.lock');
        try {
            $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

            expect($result->subProjects['apps/bad']->composer->errorCode)->toBe('dependencies.unsafe_source');
            expect($result->subProjects['packages/good']->javascript->succeeded())->toBeTrue();
            expect(is_link($path.'/apps/bad/composer.lock'))->toBeTrue();
        } finally {
            new Filesystem()->deleteDirectory($path);
        }
    });

    it('records no skipped projects when discovery finds no qualifying sources', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo']);
        $path = dependency_scan_local_source($instance);
        new Filesystem()->ensureDirectoryExists($path.'/apps/native');
        file_put_contents($path.'/apps/native/package.json', '{}');
        file_put_contents($path.'/apps/native/yarn.lock', 'unsupported');
        try {
            $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

            expect($result->succeeded())->toBeTrue();
            expect($result->subProjects)->toBe([]);
            expect(app(ReadInstanceDependencyScanAction::class)->subProjects($instance->id))->toBe([]);
            $this->assertDatabaseCount('instance_dependency_scan_attempts', 0);
        } finally {
            new Filesystem()->deleteDirectory($path);
        }
    });

    it('returns busy without recording an attempt when another lifecycle owner holds the instance', function (): void {
        $instance = dependency_scan_instance();
        $directory = sys_get_temp_dir().'/orbit-scan-busy-'.Str::uuid();
        $owner = new NativeInstanceEnvironmentOperationLock($directory, new CommandDeadline);
        $time = 0.0;
        $clock = static function () use (&$time): float {
            return $time;
        };
        $contender = new NativeInstanceEnvironmentOperationLock($directory, new CommandDeadline($clock), $clock, static function (int $wait) use (&$time): void {
            $time += $wait / 1_000_000;
        });
        app()->instance(InstanceEnvironmentOperationLock::class, $contender);
        mock(SshExecutor::class)->shouldNotReceive('execute');
        try {
            $result = $owner->run([$instance->id], fn () => app(ScanInstanceDependenciesAction::class)->execute($instance));
            expect($result->composer->errorCode)->toBe('dependencies.operation_busy');
            expect($result->javascript->errorCode)->toBe('dependencies.operation_busy');
            $this->assertDatabaseCount('instance_dependency_scan_attempts', 0);
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });
});
