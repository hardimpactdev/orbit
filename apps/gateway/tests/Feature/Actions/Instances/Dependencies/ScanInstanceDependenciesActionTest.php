<?php

declare(strict_types=1);

use App\Actions\Instances\Dependencies\CollectInstanceDependencyFilesAction;
use App\Actions\Instances\Dependencies\ReadInstanceDependencyScanAction;
use App\Actions\Instances\Dependencies\ScanInstanceDependenciesAction;
use App\Data\Instances\Dependencies\DependencyInventoryData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Instances\Dependencies\DependencyInventoryState;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\InstanceDependencyScanAttempt;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

function dependency_scan_instance(): Instance
{
    $project = Project::query()->create(['slug' => 'dependency-scan', 'name' => 'Dependency scan', 'repository_url' => 'https://example.test/scan.git']);
    $node = Node::query()->create(['name' => 'dependency-scan', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.180', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit', 'status' => 'active']);
    orbit_test_set_app_placement_role($node, false);

    return $project->instances()->create([
        'node_id' => $node->id, 'name' => 'scan', 'environment' => 'development',
        'checkout_path' => '/home/orbit/project', 'source_layout' => 'checkout', 'status' => 'active',
    ]);
}

/** @return array<string, mixed> */
function dependency_scan_receipt(string $manager = 'npm'): array
{
    $files = array_fill_keys(CollectInstanceDependencyFilesAction::FILES, ['content' => null, 'hash' => null, 'error' => null]);
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
    app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner()
        ->answer('instance.read-dependency-files', fn (): array => $response())
        ->answer('instance.read-release-dependency-files', fn (): array => $response()));
}

/** @return array<string, mixed> */
function dependency_scan_source_receipt(string $relative, string $ecosystem): array
{
    $receipt = dependency_scan_receipt();
    $receipt['root'] .= $relative;
    foreach ($receipt['files'] as $name => &$file) {
        if (str_starts_with($name, 'composer') !== ($ecosystem === 'composer')) {
            $file = ['content' => null, 'hash' => null, 'error' => null];
        }
    }

    return $receipt;
}

/** @param array<string, string> $projects
 * @return array<string, mixed>
 */
function dependency_scan_project_receipt(array $projects): array
{
    $receipt = dependency_scan_receipt();
    $receipt['files'] = array_fill_keys(CollectInstanceDependencyFilesAction::FILES, ['content' => null, 'hash' => null, 'error' => null]);
    $receipt['sub_projects'] = [];
    foreach ($projects as $relative => $ecosystem) {
        $receipt['sub_projects'][$relative] = dependency_scan_source_receipt('/'.$relative, $ecosystem);
    }

    return $receipt;
}

describe('coordinated instance dependency scans', function (): void {
    it('stores safe fleet error details and returns them on a later show', function (array $changes, string $ecosystem, string $code, string $detail): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt('bun');
        dependency_scan_remote(function () use (&$receipt): array {
            return $receipt;
        });
        $scan = app(ScanInstanceDependenciesAction::class);
        $before = $scan->execute($instance);
        foreach ($changes as $name => $content) {
            $receipt['files'][$name] = [
                'content' => $content === null ? null : base64_encode($content),
                'hash' => $content === null ? null : hash('sha256', $content),
                'error' => null,
            ];
        }

        $result = $scan->execute($instance)->{$ecosystem};
        $shown = app(ReadInstanceDependencyScanAction::class)->execute(
            $instance->id, $result->ecosystem,
        );

        expect($result->errorCode)->toBe($code);
        expect($result->errorDetail)->toBe($detail);
        expect($shown->errorDetail)->toBe($detail);
        expect($shown->snapshot)->toEqual($before->{$ecosystem}->snapshot);
        $data = DependencyInventoryData::fromResult($result->ecosystem, $shown)->toArray();
        expect($data['error_detail'])->toBe($detail);
        expect(json_encode($data, JSON_THROW_ON_ERROR))->not->toContain('fleet-secret', 'fixture-secret');
        $this->assertDatabaseHas('instance_dependency_scan_attempts', ['instance_id' => $instance->id, 'error_code' => $code, 'error_detail' => $detail]);
    })->with([
        'Composer missing locked dependency' => [
            ['composer.json' => '{"require":{"sample/missing":"*"},"description":"fleet-secret"}'],
            'composer', 'dependencies.invalid_composer_input',
            'composer.json or composer.lock requirement has no unique locked package or provider',
        ],
        'competing npm and Bun locks without a declaration' => [
            ['package.json' => '{}', 'package-lock.json' => '{}'],
            'javascript', 'dependencies.ambiguous_manager', 'conflicting manager files: package-lock.json, bun.lock',
        ],
        'Yarn lock' => [
            ['package.json' => '{}', 'bun.lock' => null, 'yarn.lock' => '# yarn lockfile v1'],
            'javascript', 'dependencies.unsupported_format', 'yarn.lock is not supported by the dependency scan',
        ],
        'binary Bun lock' => [
            ['package.json' => '{}', 'bun.lock' => null, 'bun.lockb' => "#!/usr/bin/env bun\n\0fleet-secret"],
            'javascript', 'dependencies.unsupported_format', 'bun.lockb is binary; commit a text bun.lock to scan',
        ],
        'Bun workspace root differs from manifest' => [
            [
                'package.json' => '{"dependencies":{"lucide-react":"^1.47.0"}}',
                'bun.lock' => '{"lockfileVersion":1,"workspaces":{"":{"dependencies":{"lucide-react":"^1.45.0"}}},"packages":{"lucide-react":["lucide-react@1.45.0","",{},""]}}',
            ],
            'javascript', 'dependencies.stale_bun_lockfile', 'bun.lock workspace dependencies do not match package.json',
        ],
        'malformed Composer repository' => [
            ['composer.json' => '{"repositories":[123]}'],
            'composer', 'dependencies.invalid_composer_input', 'Composer repository entry must be an object or false',
        ],
    ]);

    it('scans the declared manager despite stray locks and configuration', function (string $manager, array $strays): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt($manager);
        $manifest = json_decode(base64_decode($receipt['files']['package.json']['content']), true, flags: JSON_THROW_ON_ERROR);
        $manifest['packageManager'] = $manager.'@1.0.0';
        foreach (['package.json' => json_encode($manifest, JSON_THROW_ON_ERROR), ...$strays] as $name => $content) {
            $receipt['files'][$name] = ['content' => base64_encode($content), 'hash' => hash('sha256', $content), 'error' => null];
        }
        dependency_scan_remote(fn (): array => $receipt);

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);
        $shown = app(ReadInstanceDependencyScanAction::class)->execute($instance->id, $result->javascript->ecosystem);

        expect($result->succeeded())->toBeTrue();
        expect($shown->errorCode)->toBeNull();
        expect($shown->errorDetail)->toBeNull();
        expect($shown->snapshot->graph)->toEqual($result->javascript->snapshot->graph);
    })->with([
        ['npm', ['bun.lock' => '{}', 'yarn.lock' => 'stray', 'pnpm-workspace.yaml' => 'stray']],
        ['pnpm', ['bun.lock' => '{}', 'package-lock.json' => '{}']],
        ['bun', ['package-lock.json' => '{}', '.pnpmfile.cjs' => 'throw new Error("must-not-execute")']],
    ]);

    it('scans Composer 2.9 metadata and remote VCS repositories without executing or exposing them', function (): void {
        $instance = dependency_scan_instance();
        $receipt = dependency_scan_receipt();
        foreach (['composer.json' => 'manifest', 'composer.lock' => 'lock'] as $name => $suffix) {
            $content = file_get_contents(base_path('tests/Fixtures/Dependencies/Composer/composer29-'.$suffix.'.json'));
            $receipt['files'][$name] = ['content' => base64_encode($content), 'hash' => hash('sha256', $content), 'error' => null];
        }
        dependency_scan_remote(fn (): array => $receipt);

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->composer->succeeded())->toBeTrue();
        expect($result->composer->snapshot->graph->resolutions[0]->version)->toBe('dev-main');
        expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('fixture-user', 'fixture-secret', 'must-not-execute');
    });

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
                $receipt['files'] = array_fill_keys(CollectInstanceDependencyFilesAction::FILES, ['content' => null, 'hash' => null, 'error' => null]);
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
        $receipt['files'] = array_fill_keys(CollectInstanceDependencyFilesAction::FILES, ['content' => null, 'hash' => null, 'error' => null]);
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
        app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner);

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

    it('stores a monorepo path package as local and still fails real sibling errors', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo']);
        $receipt = dependency_scan_project_receipt(['apps/cli' => 'composer', 'apps/web' => 'npm']);
        foreach (['composer.json' => 'manifest', 'composer.lock' => 'lock'] as $file => $fixture) {
            $content = file_get_contents(base_path('tests/Fixtures/Dependencies/Composer/path-'.$fixture.'.json'));
            $receipt['sub_projects']['apps/cli']['files'][$file] = ['content' => base64_encode($content), 'hash' => hash('sha256', $content), 'error' => null];
        }
        dependency_scan_remote(function () use (&$receipt): array {
            return $receipt;
        });
        $scan = app(ScanInstanceDependenciesAction::class);
        $first = $scan->execute($instance);
        expect($first->succeeded())->toBeTrue();
        $stored = app(ReadInstanceDependencyScanAction::class)->subProjects($instance->id);
        expect($stored)->toEqual($first->subProjects);
        $local = collect($stored['apps/cli']->composer->snapshot->graph->resolutions)->firstWhere('package.name', 'local/sdk');
        expect($local->id)->toBe('path:local/sdk');
        expect($local->sourceReference)->toBe('abc123');
        expect([$local->regular, $local->development])->toBe([true, true]);
        $data = DependencyInventoryData::fromResult($stored['apps/cli']->composer->ecosystem, $stored['apps/cli']->composer)->toArray();
        expect(json_encode($data, JSON_THROW_ON_ERROR))->not->toContain('fixture-secret', '../../../outside');
        $this->assertDatabaseHas('instance_dependency_resolutions', ['locator' => 'path:local/sdk', 'version' => '1.2.3']);
        $receipt['sub_projects']['apps/web']['files']['package-lock.json'] = ['content' => base64_encode('{}'), 'hash' => hash('sha256', '{}'), 'error' => null];
        $second = $scan->execute($instance);
        expect($second->succeeded())->toBeFalse();
        expect($second->subProjects['apps/cli']->succeeded())->toBeTrue();
        expect($second->subProjects['apps/web']->javascript->errorCode)->toBe('dependencies.unsupported_format');
    });

    it('preserves separate sub-project inventories and the last valid snapshot after a partial failure', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo']);
        $receipt = dependency_scan_project_receipt(['apps/api' => 'composer', 'packages/other' => 'npm', 'packages/widget' => 'npm']);
        $lock = &$receipt['sub_projects']['packages/other']['files']['package-lock.json'];
        $content = str_replace('"version": "1.1.0"', '"version": "1.2.0"', base64_decode($lock['content']));
        $lock = ['content' => base64_encode($content), 'hash' => hash('sha256', $content), 'error' => null];
        dependency_scan_remote(function () use (&$receipt): array {
            return $receipt;
        });
        $scan = app(ScanInstanceDependenciesAction::class);
        $first = $scan->execute($instance);
        $lock = ['content' => base64_encode('{}'), 'hash' => hash('sha256', '{}'), 'error' => null];

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
    });

    it('requests repository discovery with the nested application as fallback and records siblings', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo', 'root' => 'apps/gateway/public']);
        $instance->update(['source_is_laravel' => true]);
        $receipt = dependency_scan_project_receipt(['apps/gateway' => 'composer', 'apps/sibling' => 'composer', 'packages/widget' => 'npm']);
        $agents = new FakeAgentCommandRunner()->answer('instance.read-dependency-files', $receipt);
        app()->instance(AgentCommandRunner::class, $agents);

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->succeeded())->toBeTrue();
        expect(array_keys($result->subProjects))->toBe(['apps/gateway', 'apps/sibling', 'packages/widget']);
        expect($result->subProjects['apps/gateway']->composer->snapshot->source->projectRoot)->toBe('/home/orbit/project/apps/gateway');
        expect($result->subProjects['apps/sibling']->composer->snapshot->source->projectRoot)->toBe('/home/orbit/project/apps/sibling');
        expect($result->subProjects['packages/widget']->javascript->snapshot->source->projectRoot)->toBe('/home/orbit/project/packages/widget');
        expect(array_keys(app(ReadInstanceDependencyScanAction::class)->subProjects($instance->id)))->toBe(['apps/gateway', 'apps/sibling', 'packages/widget']);
        expect($agents->calls)->toHaveCount(2);
        foreach ($agents->calls as $call) {
            expect($call['input'])->toBe(['path' => '/home/orbit/project', 'application' => '/apps/gateway', 'monorepo' => true]);
        }
        $this->assertDatabaseCount('instance_dependency_observations', 6);
        $this->assertDatabaseMissing('instance_dependency_scan_attempts', ['instance_id' => $instance->id, 'sub_project' => '']);
    });

    it('records the nested fallback returned when monorepo discovery is unavailable', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo', 'root' => 'server/web/public']);
        $instance->update(['source_is_laravel' => true]);
        $receipt = dependency_scan_source_receipt('/server/web', 'composer');
        $receipt['sub_projects'] = null;
        dependency_scan_remote(fn (): array => $receipt);

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->succeeded())->toBeTrue();
        expect($result->subProjects)->toBeNull();
        expect($result->composer->snapshot->source->projectRoot)->toBe('/home/orbit/project/server/web');
        expect($result->javascript->state())->toBe(DependencyInventoryState::Absent);
        $this->assertDatabaseCount('instance_dependency_observations', 2);
        $this->assertDatabaseMissing('instance_dependency_scan_attempts', ['instance_id' => $instance->id, 'sub_project' => 'apps/ignored']);
    });

    it('selects discovery only for monorepos and accepts a single source result', function (string $type): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => $type]);
        $agents = new FakeAgentCommandRunner()->answer('instance.read-dependency-files', dependency_scan_source_receipt('', 'composer'));
        app()->instance(AgentCommandRunner::class, $agents);

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->succeeded())->toBeTrue();
        expect($result->subProjects)->toBeNull();
        expect($result->composer->snapshot->source->projectRoot)->toBe('/home/orbit/project');
        expect($agents->calls)->toHaveCount(2);
        foreach ($agents->calls as $call) {
            expect($call['input'])->toBe(['path' => '/home/orbit/project', 'application' => '', 'monorepo' => $type === 'monorepo']);
        }
        $this->assertDatabaseCount('instance_dependency_observations', 2);
    })->with(['monorepo', 'node-package']);

    it('records unsafe sub-project files without discarding valid sibling inventories', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo']);
        $receipt = dependency_scan_project_receipt(['apps/bad' => 'composer', 'packages/good' => 'npm']);
        $receipt['sub_projects']['apps/bad']['files']['composer.lock'] = ['content' => null, 'hash' => null, 'error' => 'dependencies.unsafe_source'];
        dependency_scan_remote(fn (): array => $receipt);

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->subProjects['apps/bad']->composer->errorCode)->toBe('dependencies.unsafe_source');
        expect($result->subProjects['packages/good']->javascript->succeeded())->toBeTrue();
        expect(app(ReadInstanceDependencyScanAction::class)->subProjects($instance->id))->toEqual($result->subProjects);
    });

    it('records no skipped projects when discovery finds no qualifying sources', function (): void {
        $instance = dependency_scan_instance();
        $instance->project->update(['type' => 'monorepo']);
        dependency_scan_remote(fn (): array => dependency_scan_project_receipt([]));

        $result = app(ScanInstanceDependenciesAction::class)->execute($instance);

        expect($result->succeeded())->toBeTrue();
        expect($result->subProjects)->toBe([]);
        expect(app(ReadInstanceDependencyScanAction::class)->subProjects($instance->id))->toBe([]);
        $this->assertDatabaseCount('instance_dependency_scan_attempts', 0);
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
        app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner);
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
