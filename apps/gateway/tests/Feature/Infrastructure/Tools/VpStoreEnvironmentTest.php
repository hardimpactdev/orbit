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
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Tools\ToolCommandBudget;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\UnsupportedPackageInventory;
use Tests\Support\VpStoreAgentRunner;

/** @return array{PrepareVpEnvironmentAction, VpStoreAgentRunner, Instance, RemoteProductionDeployment, VpToolManager} */
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
    $agent = new VpStoreAgentRunner;
    $vp = new VpToolManager(new SemverVersionNormalizer, $agent, app(ToolCommandBudget::class));
    $prepare = new PrepareVpEnvironmentAction($vp, $agent, app(ToolManagerScopeLock::class));
    // Pruning reports what the scripted Node removed or found in use.
    $agents = new FakeAgentCommandRunner()
        ->answer('instances.prune-releases', static fn (): array => ['removed' => $agent->removedReleases, 'in_use' => $agent->busyReleases])
        ->answer('instances.run-deploy-step', static function () use ($agent): array {
            $agent->operations[] = 'build';

            return ['exit_code' => $agent->buildFails ? 1 : 0, 'output' => [], 'truncated' => false];
        });
    $deployment = new RemoteProductionDeployment($agents, app(RepositoryReadAccess::class), $prepare);

    return [$prepare, $agent, $instance, $deployment, $vp];
}

function vp_store_build(RemoteProductionDeployment $deployment, Instance $instance): void
{
    $deployment->executeStep($instance, new DeploymentRelease('candidate', '/home/orbit-app-1/releases/candidate', str_repeat('a', 40)), new DeploymentStep('npm-build', DeploymentPhase::BeforeActivation, 'vp run build', 60), new DeploymentRequest);
}

it('installs and records missing runtimes as the owner before building and never upgrades recipe-owned vp', function (): void {
    [, $agent, $instance, $deployment] = vp_store_fixture();
    $agent->configuration['npmrc'] = "registry=https://private.example/\n//private.example/:_authToken=secret";
    $agent->onInstall = function () use ($instance): void {
        expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'installing', 'node_version' => '24.22.0']);
    };
    vp_store_build($deployment, $instance);
    expect($agent->operations)->toContain('install', 'build', 'prune')->not->toContain('vp-upgrade', 'resolve');
    expect(array_search('install', $agent->operations))->toBeLessThan(array_search('build', $agent->operations));
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'active', 'vp_version' => '0.3.3', 'package_manager_version' => '11.12.0']);
    $install = array_values(array_filter($agent->agentCalls, fn (array $call): bool => $call['command'] === 'tools.install-vp-environment'))[0];
    expect($install['input']['npmrc'])->toContain('private.example');
    expect($install['input']['environment'])->toMatchArray(['node_version' => '24.22.0', 'package_manager' => 'npm']);
    expect($agent->agentCalls[0])->toBe(['command' => 'tools.read-vp-project', 'input' => ['path' => '/home/orbit-app-1/releases/candidate', 'user' => 'orbit-app-1']]);
    Http::assertNothingSent();
});

it('takes a read-only lock-free fast path and adopts existing Node npm or recipe pnpm without registry requests', function (string $manager, string $version): void {
    [$prepare, $agent, $instance, , $vp] = vp_store_fixture();
    $agent->installed = true;
    if ($manager === 'pnpm') {
        $agent->configuration['package']['packageManager'] = 'pnpm@'.$version;
        $agent->configuration['locks'] = ['pnpm-lock.yaml' => "lockfileVersion: '9.0'\nimporters:\n  .:\n    devDependencies:\n      vite-plus:\n        specifier: 'catalog:'\n        version: 0.3.3\n"];
    }
    $lock = new class implements ToolManagerScopeLock
    {
        public function run(int $nodeId, ToolManagerName $manager, Closure $callback): mixed
        {
            throw new RuntimeException('Fast path took the lock');
        }
    };
    $prepare = new PrepareVpEnvironmentAction($vp, $agent, $lock);
    $first = $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build');
    $second = $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build');
    expect($first)->toBe($second)->toContain("'--package-manager' '{$manager}@{$version}'");
    expect($agent->operations)->not->toContain('install', 'prune', 'resolve', 'vp-upgrade');
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'active', 'package_manager' => $manager, 'previous' => null]);
    Http::assertNothingSent();
})->with([['npm', '11.12.0'], ['pnpm', '10.17.0']]);

it('fails early for old recipe vp without mutating it', function (): void {
    [$prepare, $agent, $instance] = vp_store_fixture();
    $agent->vpVersion = '0.3.0';
    expect(fn () => $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build'))->toThrow(ResourceOperationException::class);
    expect($agent->operations)->not->toContain('install', 'build', 'vp-upgrade');
});

it('fails before build when installation fails or remains incomplete', function (bool $incomplete): void {
    [, $agent, $instance, $deployment] = vp_store_fixture();
    $agent->installFails = ! $incomplete;
    $agent->installIncomplete = $incomplete;
    expect(fn () => vp_store_build($deployment, $instance))->toThrow(ResourceOperationException::class);
    expect($agent->operations)->not->toContain('build');
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'failed', 'error_code' => 'deployment.vp_store_failed']);
})->with([true, false]);

it('rejects missing locks and moving Node requirements without registry resolution', function (bool $missingLock): void {
    [$prepare, $agent, $instance] = vp_store_fixture();
    if ($missingLock) {
        $agent->configuration['locks'] = [];
    } else {
        $agent->configuration['.node-version'] = 'lts/*';
    }
    try {
        $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build');
        test()->fail('Expected invalid requirements');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('deployment.vp_requirements_invalid');
    }
    expect($agent->operations)->not->toContain('install', 'build');
    Http::assertNothingSent();
})->with([true, false]);

it('retains other Instance and selected release references until release cleanup retires obsolete requirements', function (): void {
    [$prepare, $agent, $instance, $deployment] = vp_store_fixture();
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
    $input = array_values(array_filter($agent->inputs, fn (array $input): bool => isset($input['references'])))[0];
    expect(array_column($input['references'], 'node_version'))->toContain('22.18.0', '24.22.0');
    $agent->configuration['package'] = [];
    $path = '/home/orbit-app-1/releases/no-vp';
    $prepare->command($instance, $path, 'npm-build', 'npm run build');
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['node_version' => '24.22.0']);
    $instance->update(['checkout_path' => $path]);
    $agent->removedReleases = ['initial'];
    $deployment->prune($instance, new DeploymentRelease('no-vp', $path, str_repeat('a', 40)), null);
    expect($instance->fresh()->getAttribute('vp_environment'))->toBeNull();
    $collections = array_values(array_filter($agent->inputs, fn (array $input): bool => isset($input['references'])));
    expect(array_column($collections[array_key_last($collections)]['references'], 'node_version'))->toBe(['22.18.0']);
});

it('keeps the live runtime through an upgrade build failure and retains it for rollback after a successful retry', function (): void {
    [, $agent, $instance, $deployment] = vp_store_fixture();
    $live = new DeploymentRelease('initial', $instance->checkout_path, str_repeat('a', 40));
    $failed = new DeploymentRelease('candidate-failed', '/home/orbit-app-1/releases/candidate-failed', str_repeat('b', 40));
    $candidate = new DeploymentRelease('candidate', '/home/orbit-app-1/releases/candidate', str_repeat('c', 40));
    $oldRuntimes = ['js_runtime/node/22.18.0', 'package_manager/npm/10.9.0'];
    $newRuntimes = ['js_runtime/node/24.22.0', 'package_manager/npm/11.12.0'];
    $agent->managedRuntimes = $oldRuntimes;
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
    $agent->buildFails = true;

    $failure = app(DeployInstanceAction::class)->execute($instance, operation_steps($instance, app(DeployInstanceAction::class)), DeploymentRequest::withoutOutput());

    expect($failure->succeeded)->toBeFalse();
    expect($failure->failure?->boundary)->toBe(DeploymentFailureBoundary::BeforeActivation);
    expect($instance->fresh()->checkout_path)->toBe($live->path);
    expect($activations)->toBe([]);
    expect($agent->managedRuntimes)->toEqualCanonicalizing([...$oldRuntimes, ...$newRuntimes]);
    $collections = array_values(array_filter($agent->inputs, fn (array $input): bool => isset($input['references'])));
    expect(array_column($collections[0]['references'], 'node_version'))->toContain('22.18.0', '24.22.0');
    $agent->buildFails = false;
    $agent->removedReleases = [$failed->name];

    $success = app(DeployInstanceAction::class)->execute($instance, operation_steps($instance, app(DeployInstanceAction::class)), DeploymentRequest::withoutOutput());

    expect($success->succeeded)->toBeTrue();
    expect($instance->fresh()->checkout_path)->toBe($candidate->path);
    expect($agent->managedRuntimes)->toEqualCanonicalizing([...$oldRuntimes, ...$newRuntimes]);
    $operationsBeforeRollback = $agent->operations;

    $rollback = app(RollbackInstanceAction::class)->execute($instance, 'initial', operation_steps($instance, app(RollbackInstanceAction::class)), DeploymentRequest::withoutOutput());

    expect($rollback->succeeded)->toBeTrue();
    expect($instance->fresh()->checkout_path)->toBe($live->path);
    expect($activations)->toBe(['candidate', 'initial']);
    expect($agent->managedRuntimes)->toEqualCanonicalizing([...$oldRuntimes, ...$newRuntimes]);
    expect($agent->operations)->toBe($operationsBeforeRollback);
});

it('releases runtime references only for retired releases and keeps busy or selected releases', function (): void {
    [$prepare, $agent, $instance, $deployment] = vp_store_fixture();
    $oldRuntimes = ['js_runtime/node/22.18.0', 'package_manager/npm/10.9.0'];
    $newRuntimes = ['js_runtime/node/24.22.0', 'package_manager/npm/11.12.0'];
    $agent->managedRuntimes = $oldRuntimes;
    $instance->update(['vp_environment' => (new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '22.18.0', 'npm', '10.9.0'))->record('active')]);
    $candidate = new DeploymentRelease('candidate', '/home/orbit-app-1/releases/candidate', str_repeat('a', 40));
    $prepare->command($instance, $candidate->path, 'npm-build', 'vp run build');
    $instance->update(['checkout_path' => $candidate->path]);
    $agent->busyReleases = ['initial'];

    $outcome = $deployment->prune($instance, $candidate, null);

    expect($outcome)->toBe(['removed' => [], 'in_use' => ['initial']]);
    expect($agent->managedRuntimes)->toEqualCanonicalizing([...$oldRuntimes, ...$newRuntimes]);
    $agent->busyReleases = [];
    $agent->removedReleases = ['initial'];

    $deployment->prune($instance, $candidate, null);

    expect($agent->managedRuntimes)->toEqualCanonicalizing($newRuntimes);
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['checkout_path' => $candidate->path, 'previous' => null]);
    $prepare->releaseRemoved($instance, [$candidate->path]);
    expect($agent->managedRuntimes)->toEqualCanonicalizing($newRuntimes);
});

it('batches Doctor reads and classifies failed requirements separately from active missing content', function (string $status, bool $installed, string $expected): void {
    [, $agent, $instance, , $vp] = vp_store_fixture();
    $agent->installed = $installed;
    $instance->update(['vp_environment' => (new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '24.22.0', 'npm', '11.12.0'))->record($status)]);
    $probe = new ToolDoctorProbe(app(ToolInspector::class), new VersionConstraint, new UnsupportedPackageInventory, $vp, app(ToolCommandBudget::class), app(ToolManagerRegistry::class));
    $row = ProblemFingerprint::query()->create(['fingerprint' => 'doctor|tool.vp_store_missing|tool|node:'.$instance->node_id.':environments', 'source' => ProblemSource::Doctor, 'occurrences' => 0, 'evidence' => ['doctor_node' => (string) $instance->node_id, 'doctor_family' => 'tool', 'doctor_code' => 'tool.vp_store_missing', 'doctor_resource' => 'node:'.$instance->node_id.':environments', 'doctor_observed_at' => now()->toISOString()]]);
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
    expect($agent->operations)->not->toContain('install', 'prune');
    expect(count($agent->operations))->toBeLessThanOrEqual(5);
    if ($status === 'active') {
        expect($agent->deadlines)->not->toBeEmpty();
        foreach ($agent->deadlines as $deadline) {
            expect($deadline)->toBe(15.0);
        }
    } else {
        expect($agent->deadlines)->toBe([]);
    }
})->with([
    ['active', true, ''], ['active', false, 'tool.vp_store_missing'],
    ['failed', false, 'tool.vp_store_inspection_failed'], ['installing', false, 'tool.vp_store_inspection_failed'],
]);

it('maps unreadable project data to the requirements error before any store operation', function (): void {
    [, $agent, $instance, , $vp] = vp_store_fixture();
    $agents = new FakeAgentCommandRunner()->refuse('tools.read-vp-project', 'tools.project_invalid');
    $prepare = new PrepareVpEnvironmentAction($vp, $agents, app(ToolManagerScopeLock::class));

    expect(fn () => $prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build'))
        ->toThrow(ResourceOperationException::class);
    expect($instance->fresh()->getAttribute('vp_environment'))->toMatchArray(['status' => 'failed', 'error_code' => 'deployment.vp_requirements_invalid']);
    expect($agents->calls)->toHaveCount(1);
    expect($agent->operations)->toBe([]);
});

it('reads development requirements as the enrolled account', function (): void {
    [, $agent, $instance, , $vp] = vp_store_fixture();
    $instance->node->roles()->delete();
    $instance->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $instance->unsetRelation('node');
    $agents = new FakeAgentCommandRunner()->answer('tools.read-vp-project', ['package' => []]);
    $prepare = new PrepareVpEnvironmentAction($vp, $agents, app(ToolManagerScopeLock::class));

    expect($prepare->command($instance, $instance->checkout_path, 'npm-build', 'vp run build'))->toBe('vp run build');
    expect($agents->calls[0]['input'])->toBe(['path' => $instance->checkout_path, 'user' => 'orbit']);
    expect($agent->operations)->toBe([]);
});
