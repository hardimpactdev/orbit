<?php

declare(strict_types=1);

use App\Actions\Doctor\ToolDoctorProbe;
use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\RollbackInstanceAction;
use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizer;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Problems\ProblemSource;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\VersionConstraint;
use App\Domain\Tools\VpEnvironment;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\RemotePrograms\SshProgramRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Infrastructure\Tools\ToolCommandBudget;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Tests\Support\UnsupportedPackageInventory;
use Tests\Support\VpStoreSshExecutor;

/** @return array{PrepareVpEnvironmentAction, VpStoreSshExecutor, Instance, RemoteProductionDeployment, VpToolManager} */
function vp_store_fixture(): array
{
    Http::preventStrayRequests();
    $node = Node::query()->create(['name' => 'vp-store', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.35', 'wireguard_ip' => '10.44.0.35', 'user' => 'orbit']);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Vp store', 'slug' => 'vp-store', 'repository_url' => 'https://example.test/vp-store.git', 'default_branch' => 'main', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production',
        'source_layout' => 'release', 'checkout_path' => '/home/orbit-app-1/releases/initial',
        'production_user' => 'orbit-app-1', 'production_home' => '/home/orbit-app-1',
        'root' => 'public', 'branch' => 'main', 'provisioning_step' => 'active', 'status' => 'active',
    ]);
    $ssh = new VpStoreSshExecutor;
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-vp-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 test';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-vp-test-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $vp = new VpToolManager(new RemoteToolCommandRunner($ssh, $keys, $hosts, app(ToolCommandBudget::class)), new SemverVersionNormalizer);
    $catalog = new RemoteProgramCatalog;
    $prepare = new PrepareVpEnvironmentAction($vp, new SshProgramRunner($ssh, $keys, $hosts, $catalog), app(ToolManagerScopeLock::class), $catalog);
    $deployment = new RemoteProductionDeployment(new ProductionSshExecutor($ssh, $keys, $hosts), app(RepositoryReadAccess::class), $prepare);

    return [$prepare, $ssh, $instance, $deployment, $vp];
}

function vp_store_build(RemoteProductionDeployment $deployment, Instance $instance): void
{
    $deployment->executeStep($instance, new DeploymentRelease('candidate', '/home/orbit-app-1/releases/candidate', str_repeat('a', 40)), new DeploymentStep('npm-build', DeploymentPhase::BeforeActivation, 'vp run build', 60), new DeploymentRequest);
}

it('installs and records missing runtimes as the owner before building and never upgrades recipe-owned vp', function (): void {
    [, $ssh, $instance, $deployment] = vp_store_fixture();
    $ssh->configuration['npmrc'] = "registry=https://private.example/\n//private.example/:_authToken=secret";
    $ssh->onInstall = function () use ($instance): void {
        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'installing', 'node_version' => '24.22.0']);
    };
    vp_store_build($deployment, $instance);
    expect($ssh->operations)->toContain('install', 'build', 'prune')->not->toContain('vp-upgrade', 'resolve');
    expect(array_search('install', $ssh->operations))->toBeLessThan(array_search('build', $ssh->operations));
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'active', 'vp_version' => '0.3.3', 'package_manager_version' => '11.12.0']);
    $install = array_values(array_filter($ssh->commands, fn ($command): bool => in_array('tools.install-vp-environment', $command->arguments, true)))[0];
    expect($install->arguments)->toContain('python3', '-I')->not->toContain('sudo');
    expect($install->input)->toContain('private.example');
    foreach ($ssh->connections as $connection) {
        expect($connection->user)->toBe('orbit');
    }
    expect($ssh->commands[0]->arguments)->toContain('sudo', 'orbit-app-1');
    Http::assertNothingSent();
});

it('takes a read-only lock-free fast path and adopts existing Node npm or recipe pnpm without registry requests', function (string $manager, string $version): void {
    [$prepare, $ssh, $instance, , $vp] = vp_store_fixture();
    $ssh->installed = true;
    if ($manager === 'pnpm') {
        $ssh->configuration['package']['packageManager'] = 'pnpm@'.$version;
        $ssh->configuration['locks'] = ['pnpm-lock.yaml' => "lockfileVersion: '9.0'\nimporters:\n  .:\n    devDependencies:\n      vite-plus:\n        specifier: 'catalog:'\n        version: 0.3.3\n"];
    }
    $lock = new class implements ToolManagerScopeLock
    {
        public function run(int $nodeId, ToolManagerName $manager, Closure $callback): mixed
        {
            throw new RuntimeException('Fast path took the lock');
        }
    };
    $reflection = new ReflectionProperty($prepare, 'programs');
    $prepare = new PrepareVpEnvironmentAction($vp, $reflection->getValue($prepare), $lock, new RemoteProgramCatalog);
    $first = $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build');
    $second = $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build');
    expect($first)->toBe($second)->toContain("'--package-manager' '{$manager}@{$version}'");
    expect($ssh->operations)->not->toContain('install', 'prune', 'resolve', 'vp-upgrade');
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'active', 'package_manager' => $manager, 'previous' => null]);
    Http::assertNothingSent();
})->with([['npm', '11.12.0'], ['pnpm', '10.17.0']]);

it('fails early for old recipe vp without mutating it', function (): void {
    [$prepare, $ssh, $instance] = vp_store_fixture();
    $ssh->vpVersion = '0.3.0';
    expect(fn () => $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build'))->toThrow(ResourceOperationException::class);
    expect($ssh->operations)->not->toContain('install', 'build', 'vp-upgrade');
});

it('fails before build when installation fails or remains incomplete', function (bool $incomplete): void {
    [, $ssh, $instance, $deployment] = vp_store_fixture();
    $ssh->installFails = ! $incomplete;
    $ssh->installIncomplete = $incomplete;
    expect(fn () => vp_store_build($deployment, $instance))->toThrow(ResourceOperationException::class);
    expect($ssh->operations)->not->toContain('build');
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'failed', 'error_code' => 'deployment.vp_store_failed']);
})->with([true, false]);

it('rejects missing locks and moving Node requirements without registry resolution', function (bool $missingLock): void {
    [$prepare, $ssh, $instance] = vp_store_fixture();
    if ($missingLock) {
        $ssh->configuration['locks'] = [];
    } else {
        $ssh->configuration['.node-version'] = 'lts/*';
    }
    try {
        $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build');
        test()->fail('Expected invalid requirements');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('deployment.vp_requirements_invalid');
    }
    expect($ssh->operations)->not->toContain('install', 'build');
    Http::assertNothingSent();
})->with([true, false]);

it('retains other Instance and selected release references until release cleanup retires obsolete requirements', function (): void {
    [$prepare, $ssh, $instance, $deployment] = vp_store_fixture();
    $project = $instance->project->replicate();
    $project->slug = 'other';
    $project->repository_url = 'https://example.test/other.git';
    $project->repository_identity = 'example.test/other';
    $project->name = 'Other';
    $project->code = 'OTH';
    $project->save();
    $other = $instance->replicate();
    $other->project_id = $project->id;
    $other->name = 'other';
    $other->checkout_path = '/home/orbit-app-2/releases/initial';
    $other->production_user = 'orbit-app-2';
    $other->production_home = '/home/orbit-app-2';
    $other->vp_environment = (new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '22.18.0', 'npm', '10.9.0'))->record('active');
    $other->save();
    $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build');
    $input = array_values(array_filter($ssh->inputs, fn (array $input): bool => isset($input['references'])))[0];
    expect(array_column($input['references'], 'node_version'))->toContain('22.18.0', '24.22.0');
    $ssh->configuration['package'] = [];
    $path = '/home/orbit-app-1/releases/no-vp';
    $prepare->command($instance, $path, 'npm-build', 'npm run build');
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['node_version' => '24.22.0']);
    $instance->update(['checkout_path' => $path]);
    $ssh->removedReleases = ['initial'];
    $deployment->prune($instance, new DeploymentRelease('no-vp', $path, str_repeat('a', 40)), null);
    expect($instance->fresh()->getAttribute('vp_environment'))->toBeNull();
    $collections = array_values(array_filter($ssh->inputs, fn (array $input): bool => isset($input['references'])));
    expect(array_column($collections[array_key_last($collections)]['references'], 'node_version'))->toBe(['22.18.0']);
});

it('keeps the live runtime through an upgrade build failure and retains it for rollback after a successful retry', function (): void {
    [, $ssh, $instance, $deployment] = vp_store_fixture();
    $live = new DeploymentRelease('initial', $instance->checkout_path, str_repeat('a', 40));
    $failed = new DeploymentRelease('candidate-failed', '/home/orbit-app-1/releases/candidate-failed', str_repeat('b', 40));
    $candidate = new DeploymentRelease('candidate', '/home/orbit-app-1/releases/candidate', str_repeat('c', 40));
    $oldRuntimes = ['js_runtime/node/22.18.0', 'package_manager/npm/10.9.0'];
    $newRuntimes = ['js_runtime/node/24.22.0', 'package_manager/npm/11.12.0'];
    $ssh->managedRuntimes = $oldRuntimes;
    $instance->update(['vp_environment' => (new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '22.18.0', 'npm', '10.9.0'))->record('active')]);
    store_deploy_steps($instance, [['name' => 'npm-build', 'phase' => 'before_activation', 'command' => 'vp run build', 'timeout_seconds' => 60]]);
    $operations = Mockery::mock(InstanceEnvironmentOperationLock::class);
    $operations->shouldReceive('run')->andReturnUsing(static fn (array $ids, Closure $callback): mixed => $callback());
    $this->instance(InstanceEnvironmentOperationLock::class, $operations);
    $environment = Mockery::mock(InstanceEnvironmentSynchronizer::class);
    $environment->shouldReceive('execute')->andReturn(new InstanceEnvironmentResult($instance->id, 'sync', false, 0));
    $this->instance(InstanceEnvironmentSynchronizer::class, $environment);
    $remote = Mockery::mock(ProductionDeployment::class);
    $remote->shouldReceive('prepare')->twice()->andReturn($failed, $candidate);
    $remote->shouldReceive('selected')->andReturnUsing(static fn (Instance $instance): DeploymentRelease => new DeploymentRelease(basename($instance->checkout_path), $instance->checkout_path, str_repeat('a', 40)));
    $remote->shouldReceive('executeStep')->andReturnUsing($deployment->executeStep(...));
    $remote->shouldReceive('prune')->once()->andReturnUsing($deployment->prune(...));
    $activations = [];
    $remote->shouldReceive('activate')->twice()->andReturnUsing(static function (Instance $instance, DeploymentRelease $release) use (&$activations): DeploymentRelease {
        $activations[] = $release->name;

        return $release;
    });
    $remote->shouldReceive('retained')->once()->withArgs(static fn (Instance $instance, string $name): bool => $name === 'initial')->andReturn($live);
    $this->instance(ProductionDeployment::class, $remote);
    $ssh->buildFails = true;

    $failure = app(DeployInstanceAction::class)->execute($instance, operation_steps($instance, app(DeployInstanceAction::class)), DeploymentRequest::withoutOutput());

    expect($failure->succeeded)->toBeFalse();
    expect($failure->failure?->boundary)->toBe(DeploymentFailureBoundary::BeforeActivation);
    expect($instance->fresh()->checkout_path)->toBe($live->path);
    expect($activations)->toBe([]);
    expect($ssh->managedRuntimes)->toEqualCanonicalizing([...$oldRuntimes, ...$newRuntimes]);
    $collections = array_values(array_filter($ssh->inputs, fn (array $input): bool => isset($input['references'])));
    expect(array_column($collections[0]['references'], 'node_version'))->toContain('22.18.0', '24.22.0');
    $ssh->buildFails = false;
    $ssh->removedReleases = [$failed->name];

    $success = app(DeployInstanceAction::class)->execute($instance, operation_steps($instance, app(DeployInstanceAction::class)), DeploymentRequest::withoutOutput());

    expect($success->succeeded)->toBeTrue();
    expect($instance->fresh()->checkout_path)->toBe($candidate->path);
    expect($ssh->managedRuntimes)->toEqualCanonicalizing([...$oldRuntimes, ...$newRuntimes]);
    $operationsBeforeRollback = $ssh->operations;

    $rollback = app(RollbackInstanceAction::class)->execute($instance, 'initial', operation_steps($instance, app(RollbackInstanceAction::class)), DeploymentRequest::withoutOutput());

    expect($rollback->succeeded)->toBeTrue();
    expect($instance->fresh()->checkout_path)->toBe($live->path);
    expect($activations)->toBe(['candidate', 'initial']);
    expect($ssh->managedRuntimes)->toEqualCanonicalizing([...$oldRuntimes, ...$newRuntimes]);
    expect($ssh->operations)->toBe($operationsBeforeRollback);
});

it('releases runtime references only for retired releases and keeps busy or selected releases', function (): void {
    [$prepare, $ssh, $instance, $deployment] = vp_store_fixture();
    $oldRuntimes = ['js_runtime/node/22.18.0', 'package_manager/npm/10.9.0'];
    $newRuntimes = ['js_runtime/node/24.22.0', 'package_manager/npm/11.12.0'];
    $ssh->managedRuntimes = $oldRuntimes;
    $instance->update(['vp_environment' => (new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '22.18.0', 'npm', '10.9.0'))->record('active')]);
    $candidate = new DeploymentRelease('candidate', '/home/orbit-app-1/releases/candidate', str_repeat('a', 40));
    $prepare->command($instance, $candidate->path, 'npm-build', 'vp run build');
    $instance->update(['checkout_path' => $candidate->path]);
    $ssh->busyReleases = ['initial'];

    $outcome = $deployment->prune($instance, $candidate, null);

    expect($outcome)->toBe(['removed' => [], 'in_use' => ['initial']]);
    expect($ssh->managedRuntimes)->toEqualCanonicalizing([...$oldRuntimes, ...$newRuntimes]);
    $ssh->busyReleases = [];
    $ssh->removedReleases = ['initial'];

    $deployment->prune($instance, $candidate, null);

    expect($ssh->managedRuntimes)->toEqualCanonicalizing($newRuntimes);
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['checkout_path' => $candidate->path, 'previous' => null]);
    $prepare->releaseRemoved($instance, [$candidate->path]);
    expect($ssh->managedRuntimes)->toEqualCanonicalizing($newRuntimes);
});

it('batches Doctor reads and classifies failed requirements separately from active missing content', function (string $status, bool $installed, string $expected): void {
    [, $ssh, $instance, , $vp] = vp_store_fixture();
    $ssh->installed = $installed;
    $instance->update(['vp_environment' => (new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '24.22.0', 'npm', '11.12.0'))->record($status)]);
    $probe = new ToolDoctorProbe(app(ToolInspector::class), new VersionConstraint, new UnsupportedPackageInventory, $vp, app(ToolCommandBudget::class), app(ToolManagerRegistry::class));
    $row = ProblemFingerprint::query()->create(['fingerprint' => 'doctor|tool.vp_store_missing|tool|node:'.$instance->node_id.':environments', 'source' => ProblemSource::Doctor, 'occurrences' => 0, 'evidence' => ['doctor_node' => (string) $instance->node_id, 'doctor_family' => 'tool', 'doctor_resource' => 'node:'.$instance->node_id.':environments', 'doctor_observed_at' => now()->toISOString()]]);
    $this->travel(1)->minutes();
    $report = $probe->inspect(new DoctorNodeContext($instance->node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    app(ProblemRecovery::class)->doctor(DoctorReportData::fromNodes([DoctorNodeReportData::fromFamilies($instance->node_id, $instance->node->name, [$report])]), now());
    if ($expected === '') {
        expect($row->refresh()->evidence['resolution'] ?? null)->toBe('fresh_doctor_success');
        expect($report->issues)->toBeEmpty();
    } else {
        expect($report->issues[0]->code)->toBe($expected);
        expect($row->refresh()->evidence)->not->toHaveKey('resolved_at');
    }
    expect($ssh->operations)->not->toContain('install', 'prune');
    expect(count($ssh->operations))->toBeLessThanOrEqual(5);
    foreach ($ssh->connections as $connection) {
        expect($connection->commandTimeout)->toBeLessThanOrEqual(15);
    }
})->with([
    ['active', true, ''], ['active', false, 'tool.vp_store_missing'],
    ['failed', false, 'tool.vp_store_inspection_failed'], ['installing', false, 'tool.vp_store_inspection_failed'],
]);
