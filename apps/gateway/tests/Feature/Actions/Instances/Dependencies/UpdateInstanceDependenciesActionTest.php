<?php

declare(strict_types=1);

use App\Actions\Instances\Dependencies\CollectInstanceDependencyFilesAction;
use App\Actions\Instances\Dependencies\UpdateInstanceDependenciesAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Instances\Dependencies\DependencyUpdateStepStatus;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

function dependency_update_instance(bool $production = false, ProjectType $type = ProjectType::LaravelApp): Instance
{
    $project = Project::query()->create([
        'slug' => 'dependency-update',
        'name' => 'Dependency update',
        'type' => $type,
        'repository_url' => 'https://example.test/update.git',
    ]);
    $node = Node::query()->create(['name' => 'dependency-update', 'public_ssh_host' => '192.0.2.181', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit', 'status' => 'active']);
    orbit_test_set_app_placement_role($node, $production);

    return $project->instances()->create([
        'node_id' => $node->id,
        'name' => $production ? 'production' : 'development',
        'environment' => $production ? 'production' : 'development',
        'checkout_path' => '/home/orbit/project',
        'production_user' => $production ? 'app_sample' : null,
        'production_home' => $production ? '/home/app_sample' : null,
        'source_layout' => 'checkout',
        'status' => 'active',
    ]);
}

/** @return array<string, mixed> */
function dependency_update_receipt(): array
{
    $files = array_fill_keys(CollectInstanceDependencyFilesAction::FILES, ['content' => null, 'hash' => null, 'error' => null]);
    foreach ([
        'composer.json' => 'Composer/composer2-manifest.json',
        'composer.lock' => 'Composer/composer2-lock.json',
        'package.json' => 'Npm/manifest.json',
        'package-lock.json' => 'Npm/package-lock-v3.json',
    ] as $name => $path) {
        $content = file_get_contents(base_path('tests/Fixtures/Dependencies/'.$path));
        $files[$name] = ['content' => base64_encode($content), 'hash' => hash('sha256', $content), 'error' => null];
    }

    return ['root' => '/home/orbit/project', 'reference' => null, 'identity' => str_repeat('a', 64), 'files' => $files];
}

function dependency_update_present_probe(string $vpPath = '/home/orbit/.local/share/vite-plus/bin/vp'): CommandResult
{
    return new CommandResult(0, '{"status":"present","vp":{"path":"'.$vpPath.'","version":"0.3.0"}}', '', 1, false);
}

/**
 * Answers every agent command of an update through one handler that receives its kind, such as `npm-inspect`,
 * `composer-apply`, or `collect`, and returns what the command's output would carry.
 *
 * @param  callable(string): (CommandResult|Throwable)  $handler
 */
function dependency_update_agent(callable $handler): FakeAgentCommandRunner
{
    $answer = static function (string $kind, bool $apply) use ($handler): array {
        $result = $handler($kind);
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $apply ? ['exit_code' => $result->exitCode] : json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
    };
    $agents = new FakeAgentCommandRunner()
        ->answer('instance.probe-dependency-update', static fn (array $input): array => $answer($input['ecosystem'].'-inspect', false))
        ->answer('instance.update-dependencies', static fn (array $input): array => $answer($input['ecosystem'].'-apply', true))
        ->answer('instance.read-dependency-files', static fn (): array => $answer('collect', false));
    app()->instance(AgentCommandRunner::class, $agents);

    return $agents;
}

describe('coordinated development dependency updates', function (): void {
    it('refuses production before any package invocation', function (): void {
        $instance = dependency_update_instance(production: true);
        $agents = dependency_update_agent(static fn (string $kind): Throwable => new RuntimeException($kind));

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($result->succeeded())->toBeFalse();
        expect($result->errorCode)->toBe('dependencies.production_update_forbidden');
        expect($result->mayHaveMutated())->toBeFalse();
        expect($result->inventory)->toBeNull();
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::NotRun);
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::NotRun);
        $this->assertDatabaseCount('instance_dependency_scan_attempts', 0);
    });

    it('prefights Yarn before Composer mutation', function (): void {
        $instance = dependency_update_instance();
        $kinds = [];
        dependency_update_agent(function (string $kind) use (&$kinds): CommandResult {
            $kinds[] = $kind;
            expect($kind)->toBe('yarn-inspect');

            return new CommandResult(0, '{"status":"present","family":"classic"}', '', 1, false);
        });

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($kinds)->toBe(['yarn-inspect']);
        expect($result->errorCode)->toBe('dependencies.unsupported_format');
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::NotRun);
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::NotRun);
        expect($result->inventory)->toBeNull();
        expect($result->mayHaveMutated())->toBeFalse();
        $this->assertDatabaseCount('instance_dependency_scan_attempts', 0);
    });

    it('prefights both present ecosystems before mutating Composer', function (): void {
        $instance = dependency_update_instance();
        $kinds = [];
        dependency_update_agent(function (string $kind) use (&$kinds): CommandResult {
            $kinds[] = $kind;
            expect($kinds)->not->toContain('composer-apply');

            return match ($kind) {
                'yarn-inspect', 'pnpm-inspect', 'bun-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"present"}', '', 1, false),
                'npm-inspect' => new CommandResult(0, '{"status":"incomplete"}', '', 1, false),
                default => throw new RuntimeException($kind),
            };
        });

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($kinds)->toBe(['yarn-inspect', 'composer-inspect', 'npm-inspect']);
        expect($result->errorCode)->toBe('dependencies.incomplete_source');
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::NotRun);
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::NotRun);
        expect($result->inventory)->toBeNull();
        $this->assertDatabaseCount('instance_dependency_scan_attempts', 0);
    });

    it('runs Composer then Vite+ and refreshes inventory for supported Project types', function (ProjectType $type): void {
        $instance = dependency_update_instance(type: $type);
        $kinds = [];
        $receipt = dependency_update_receipt();
        dependency_update_agent(function (string $kind) use (&$kinds, $receipt): CommandResult {
            $kinds[] = $kind;

            return match ($kind) {
                'yarn-inspect', 'pnpm-inspect', 'bun-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"present"}', '', 1, false),
                'npm-inspect' => dependency_update_present_probe(),
                'composer-apply', 'npm-apply' => new CommandResult(0, '', '', 12, false),
                'collect' => new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, false),
                default => throw new RuntimeException($kind),
            };
        });

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($kinds)->toContain('composer-apply');
        expect($kinds)->toContain('npm-apply');
        expect(array_search('composer-apply', $kinds, true))->toBeLessThan(array_search('npm-apply', $kinds, true));
        expect($result->succeeded())->toBeTrue();
        expect($result->errorCode)->toBeNull();
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->inventory?->succeeded())->toBeTrue();
        expect($result->inventory?->javascript->succeeded())->toBeTrue();
    })->with([
        'laravel-app' => ProjectType::LaravelApp,
        'laravel-package' => ProjectType::LaravelPackage,
    ]);

    it('updates the JavaScript lockfile for a node-package Project', function (): void {
        $instance = dependency_update_instance(type: ProjectType::NodePackage);
        $kinds = [];
        $receipt = dependency_update_receipt();
        dependency_update_agent(function (string $kind) use (&$kinds, $receipt): CommandResult {
            $kinds[] = $kind;

            return match ($kind) {
                'yarn-inspect', 'pnpm-inspect', 'bun-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'npm-inspect' => dependency_update_present_probe(),
                'npm-apply' => new CommandResult(0, '', '', 12, false),
                'collect' => new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, false),
                default => throw new RuntimeException($kind),
            };
        });

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($kinds)->toContain('npm-apply')
            ->and($kinds)->not->toContain('composer-apply')
            ->and($result->succeeded())->toBeTrue()
            ->and($result->composer->status)->toBe(DependencyUpdateStepStatus::Absent)
            ->and($result->javascript->status)->toBe(DependencyUpdateStepStatus::Succeeded)
            ->and($result->inventory?->succeeded())->toBeTrue();
    });

    it('stops later mutation after JavaScript failure and scans readable files', function (): void {
        $instance = dependency_update_instance();
        $kinds = [];
        $receipt = dependency_update_receipt();
        dependency_update_agent(function (string $kind) use (&$kinds, $receipt): CommandResult {
            $kinds[] = $kind;

            return match ($kind) {
                'yarn-inspect', 'pnpm-inspect', 'bun-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"present"}', '', 1, false),
                'npm-inspect' => dependency_update_present_probe(),
                'composer-apply' => new CommandResult(0, '', '', 12, false),
                'npm-apply' => new CommandResult(1, '', '', 12, false),
                'collect' => new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, false),
                default => throw new RuntimeException($kind),
            };
        });

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($kinds)->toContain('composer-apply');
        expect($kinds)->toContain('npm-apply');
        expect(array_search('composer-apply', $kinds, true))->toBeLessThan(array_search('npm-apply', $kinds, true));
        expect($kinds)->toContain('collect');
        expect($result->succeeded())->toBeFalse();
        expect($result->errorCode)->toBeNull();
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->composer->mayHaveMutated)->toBeTrue();
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::Failed);
        expect($result->javascript->errorCode)->toBe('dependencies.update_failed');
        expect($result->javascript->mayHaveMutated)->toBeTrue();
        expect($result->mayHaveMutated())->toBeTrue();
        expect($result->inventory)->not->toBeNull();
        expect($result->inventory?->succeeded())->toBeTrue();
    });

    it('stops JavaScript mutation after Composer failure and scans readable files', function (): void {
        $instance = dependency_update_instance();
        $kinds = [];
        $receipt = dependency_update_receipt();
        dependency_update_agent(function (string $kind) use (&$kinds, $receipt): CommandResult {
            $kinds[] = $kind;

            return match ($kind) {
                'yarn-inspect', 'pnpm-inspect', 'bun-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"present"}', '', 1, false),
                'npm-inspect' => dependency_update_present_probe(),
                'composer-apply' => new CommandResult(1, '', '', 12, false),
                'collect' => new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, false),
                default => throw new RuntimeException($kind),
            };
        });

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($kinds)->toContain('composer-apply');
        expect($kinds)->not->toContain('npm-apply');
        expect($kinds)->toContain('collect');
        expect($result->succeeded())->toBeFalse();
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::Failed);
        expect($result->composer->mayHaveMutated)->toBeTrue();
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::NotRun);
        expect($result->inventory)->not->toBeNull();
        expect($result->inventory?->succeeded())->toBeTrue();
    });

    it('does not report success when mutation succeeds and the post-update scan fails', function (): void {
        $instance = dependency_update_instance();
        dependency_update_agent(function (string $kind): CommandResult {

            return match ($kind) {
                'yarn-inspect', 'pnpm-inspect', 'bun-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"present"}', '', 1, false),
                'npm-inspect' => dependency_update_present_probe(),
                'composer-apply', 'npm-apply' => new CommandResult(0, '', '', 12, false),
                'collect' => new CommandResult(1, '', 'failed', 1, false),
                default => throw new RuntimeException($kind),
            };
        });

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($result->succeeded())->toBeFalse();
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->mayHaveMutated())->toBeTrue();
        expect($result->inventory?->succeeded())->toBeFalse();
        expect($result->inventory?->javascript->errorCode)->toBe('dependencies.unreadable_source');
    });

    it('does not claim success when the instance is removed after Composer mutation', function (): void {
        $instance = dependency_update_instance();
        $kinds = [];
        dependency_update_agent(function (string $kind) use (&$kinds, $instance): CommandResult {
            $kinds[] = $kind;
            if ($kind === 'composer-apply') {
                $instance->delete();
            }

            return match ($kind) {
                'yarn-inspect', 'pnpm-inspect', 'bun-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"present"}', '', 1, false),
                'npm-inspect' => dependency_update_present_probe(),
                'composer-apply' => new CommandResult(0, '', '', 12, false),
                default => throw new RuntimeException($kind),
            };
        });

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($kinds)->not->toContain('npm-apply');
        expect($result->succeeded())->toBeFalse();
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::NotRun);
        expect($result->inventory?->composer->errorCode)->toBe('dependencies.instance_unavailable');
        $this->assertDatabaseCount('instance_dependency_observations', 0);
    });

    it('does not resurrect usage when removal starts during the post-update scan', function (): void {
        $instance = dependency_update_instance();
        $receipt = dependency_update_receipt();
        $calls = 0;
        dependency_update_agent(function (string $kind) use ($instance, $receipt, &$calls): CommandResult {
            if ($kind === 'collect' && ++$calls === 1) {
                $instance->project->update(['repository_identity' => 'example.test/update']);
                $instance->update(['root' => 'public', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
                $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'generation_basis_node_id' => $instance->node_id, 'domain' => 'update.test', 'provenance' => 'generated', 'publication' => 'private', 'status' => 'pending']);
                $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
                $route->update(['status' => 'active']);
                $removal = InstanceRemoval::query()->create(['id' => (string) Str::uuid(), 'requested_instance_id' => $instance->id, 'requested_name' => $instance->name, 'force' => false, 'inventory_digest' => str_repeat('d', 64), 'total' => 1, 'status' => 'removing', 'current_step' => 'source_preparation']);
                $removal->members()->create([
                    'position' => 0, 'instance_id' => $instance->id, 'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
                    'name' => $instance->name, 'environment' => $instance->defaultAppEnv(), 'source_layout' => $instance->source_layout,
                    'checkout_path' => $instance->checkout_path, 'linked_worktree_paths' => [], 'source_digest' => str_repeat('d', 64),
                    'route_id' => $route->id, 'repository_identity' => 'example.test/update', 'root' => 'public', 'branch' => 'main',
                    'starting_commit' => str_repeat('a', 40), 'source_commit' => str_repeat('a', 40),
                    'common_repository_path' => $instance->checkout_path, 'source_identity' => "{$instance->project_id}:{$instance->id}",
                ]);
                $instance->update(['status' => 'removing']);
            }

            return match ($kind) {
                'yarn-inspect', 'pnpm-inspect', 'bun-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"present"}', '', 1, false),
                'npm-inspect' => dependency_update_present_probe(),
                'composer-apply', 'npm-apply' => new CommandResult(0, '', '', 12, false),
                'collect' => new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, false),
                default => throw new RuntimeException($kind),
            };
        });

        $result = app(UpdateInstanceDependenciesAction::class)->execute($instance);

        expect($result->succeeded())->toBeFalse();
        expect($result->composer->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->javascript->status)->toBe(DependencyUpdateStepStatus::Succeeded);
        expect($result->inventory?->composer->errorCode)->toBe('dependencies.instance_unavailable');
        $this->assertDatabaseCount('instance_dependency_resolutions', 0);
    });

    it('returns busy without mutation when another lifecycle owner holds the instance', function (): void {
        $instance = dependency_update_instance();
        $directory = sys_get_temp_dir().'/orbit-update-busy-'.Str::uuid();
        $owner = new NativeInstanceEnvironmentOperationLock($directory, new CommandDeadline);
        $time = 0.0;
        $clock = static function () use (&$time): float {
            return $time;
        };
        $contender = new NativeInstanceEnvironmentOperationLock($directory, new CommandDeadline($clock), $clock, static function (int $wait) use (&$time): void {
            $time += $wait / 1_000_000;
        });
        app()->instance(InstanceEnvironmentOperationLock::class, $contender);
        $agents = dependency_update_agent(static fn (string $kind): Throwable => new RuntimeException($kind));
        try {
            $result = $owner->run([$instance->id], fn () => app(UpdateInstanceDependenciesAction::class)->execute($instance));
            expect($result->errorCode)->toBe('dependencies.operation_busy');
            expect($result->composer->status)->toBe(DependencyUpdateStepStatus::NotRun);
            expect($result->inventory)->toBeNull();
            $this->assertDatabaseCount('instance_dependency_scan_attempts', 0);
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('holds instance and development source locks through mutation and the post-update scan', function (): void {
        $instance = dependency_update_instance();
        $directory = sys_get_temp_dir().'/orbit-update-lock-'.Str::uuid();
        $owner = new NativeInstanceEnvironmentOperationLock($directory, new CommandDeadline);
        $source = new NativeAppDevSourceOperationLock($directory);
        app()->instance(InstanceEnvironmentOperationLock::class, $owner);
        app()->instance(AppDevSourceOperationLock::class, $source);
        $receipt = dependency_update_receipt();
        $check = function () use ($directory, $instance): void {
            foreach (["app-instance-{$instance->id}", "node-{$instance->node_id}"] as $name) {
                $handle = fopen($directory.'/'.$name.'.lock', 'c+');
                expect(flock($handle, LOCK_EX | LOCK_NB))->toBeFalse();
                fclose($handle);
            }
        };
        dependency_update_agent(function (string $kind) use ($check, $receipt): CommandResult {
            $check();

            return match ($kind) {
                'yarn-inspect', 'pnpm-inspect', 'bun-inspect' => new CommandResult(0, '{"status":"absent"}', '', 1, false),
                'composer-inspect' => new CommandResult(0, '{"status":"present"}', '', 1, false),
                'npm-inspect' => dependency_update_present_probe(),
                'composer-apply', 'npm-apply' => new CommandResult(0, '', '', 12, false),
                'collect' => new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR), '', 1, false),
                default => throw new RuntimeException($kind),
            };
        });
        try {
            $result = $owner->run([$instance->id], fn () => $source->synchronized($instance->node_id, fn () => app(UpdateInstanceDependenciesAction::class)->execute($instance)));
            expect($result->succeeded())->toBeTrue();
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });
});
