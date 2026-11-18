<?php

declare(strict_types=1);

use App\Actions\Instances\ListInstanceReleasesAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Instances\StartInstanceEnvironmentImportAction;
use App\Actions\Instances\StartInstanceEnvironmentSyncAction;
use App\Actions\Instances\StartInstanceRollbackAction;
use App\Domain\Instances\Deployment\AutomaticDeployFailure;
use App\Domain\Instances\Deployment\ProductionContainerRelease;
use App\Domain\Instances\Deployment\SharedPaths;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentImportProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessOperationSecrets;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\InstanceDeployStep;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::fake(fn ($request) => Http::response(['full_name' => 'example/'.basename($request->url()), 'private' => false]));
    SwampNodeFixture::ensure();
    $this->prod = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'prod-release-test', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $this->prod->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
});

function appProdReleasePlacement(Node $node, string $name, ?string $legacy = null): Instance
{
    $project = Project::query()->create(['name' => $name, 'slug' => $name, 'type' => 'node-package', 'repository_url' => "https://github.com/example/{$name}.git",
        'default_branch' => 'main', 'root' => 'public', 'is_laravel' => false, 'source_facts_captured_at' => now()]);
    $home = '/home/orbit-app-'.$project->id;

    $instance = Instance::query()->forceCreate(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production',
        'status' => 'active', 'provisioning_step' => 'active', 'branch' => 'main', 'source_is_laravel' => false,
        'production_home' => $legacy === null ? null : $home, 'production_user' => $legacy === null ? null : basename($home),
        'checkout_path' => $legacy === null ? '/var/lib/orbit/instances/'.$project->id : $home.'/releases/initial', 'starting_commit' => $legacy]);
    InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'env_key' => 'APP_NAME', 'env_value' => $name]);

    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => $name.'.test', 'status' => 'pending']);
    $instance->routes()->attach($route->id, ['position' => 0]);
    $route->update(['status' => 'active']);

    return $instance;
}

function appProdReleaseReceipt(Operation $operation, array $overrides = [], string $status = 'succeeded', array $failure = []): Operation
{
    $run = 'native-release-'.$operation->id;
    $operation->forceFill(['state' => [...$operation->state, 'swamp_run_id' => $run]])->save();
    $release = $operation->input['production_release'];
    $receipt = [...['node_id' => $operation->node_id, 'instance_id' => $operation->target_id, 'operation_id' => $operation->id,
        'live' => $release['sha'], 'old' => $release['expected_live'], 'artifact' => 'sha256:'.str_repeat('4', 64)], ...$overrides];

    return app(DirectOperation::class)->projection($operation)->apply($operation, ['run_id' => $run,
        'status' => $status, 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'failure' => $failure, 'error_code' => $status === 'failed' ? 'deployment.after_activation_failed' : null, 'result' => ['receipt' => json_encode($receipt, JSON_THROW_ON_ERROR)]]);
}

describe('production container deployments', function (): void {
    it('holds an Instance alone, fences receipts and selects only its own rollback', function (): void {
        $first = appProdReleasePlacement($this->prod, 'one');
        $other = appProdReleasePlacement($this->prod, 'two');
        $deploy = app(StartInstanceDeployAction::class);
        $a = str_repeat('a', 40);
        $b = str_repeat('b', 40);
        $run = $deploy->execute($first, 'operator', null, $a);
        expect(app(DirectOperation::class)->workflowFor($run))->toBe('production-release')
            ->and(fn () => $deploy->execute($first, 'operator', null, $b))->toThrow(ResourceOperationException::class);
        $otherRun = $deploy->execute($other, 'operator', null, $b);
        $bad = appProdReleaseReceipt($otherRun, ['instance_id' => $first->id]);
        expect($bad->error_code)->toBe('deployment.selection_unconfirmed')->and($other->refresh()->production_release)->toBeNull();
        appProdReleaseReceipt($run);
        $second = $deploy->execute($first, 'operator', null, $b);
        $failed = appProdReleaseReceipt($second, status: 'failed');
        expect($failed->error_code)->toBe('deployment.after_activation_failed')
            ->and(app(ListInstanceReleasesAction::class)->execute($first)->releases)->toBe([substr($b, 0, 12), substr($a, 0, 12)]);
        $rollback = app(StartInstanceRollbackAction::class);
        expect(fn () => $rollback->execute($first, substr($b, 0, 12), 'operator', null))->toThrow(ResourceOperationException::class);
        $back = $rollback->execute($first, substr($a, 0, 12), 'operator', null);
        expect(app(DirectOperation::class)->workflowFor($back))->toBe('production-release');
        appProdReleaseReceipt($back);
        expect($first->refresh()->production_release['live'])->toBe($a)->and($first->production_release['old'])->toBe($b);
    });

    it('dispatches one private input to the admitted Instance and refuses changed placement material', function (): void {
        $instance = appProdReleasePlacement($this->prod, 'private-material');
        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/home/orbit-app-'.$instance->project_id.'/database.sqlite'] as $key => $value) {
            InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'env_key' => $key, 'env_value' => $value]);
        }
        $instance->project->update(['type' => 'laravel-app', 'root' => null]);
        $instance->unsetRelation('project');
        $sha = str_repeat('f', 40);
        $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null, $sha);
        $frames = new ArrayObject;
        $socket = Mockery::mock(WebSocketClient::class);
        $socket->shouldReceive('connect', 'close')->andReturnNull();
        $socket->shouldReceive('isConnected')->andReturnTrue();
        $socket->shouldReceive('send')->andReturnUsing(fn (array $frame) => $frames->append($frame));
        $socket->shouldReceive('receive')->andReturnUsing(function () use ($frames): array {
            $message = $frames[count($frames) - 1];

            return [['id' => $message['id'], 'type' => 'vault.put', 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'],
                'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]];
        });
        app()->instance(WebSocketClient::class, $socket);
        config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'fixture',
            'swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' test']);
        $direct = app(DirectOperation::class);
        $inputs = $direct->workflowInputs($operation);
        $key = 'production-release-operation-'.$operation->id;
        $private = json_decode($frames[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR);
        expect($inputs['intent']['target'])->toBe('instance-'.$instance->id)
            ->and($inputs['intent']['ssh']['target']['node_id'])->toBe($instance->node_id)
            ->and($inputs['execution_secrets'])->toBe(['release' => ['vault' => 'orbit-control-inputs', 'key' => $key]])
            ->and($private)->toMatchArray(['sha' => $sha, 'instance_id' => $instance->id, 'operation_id' => $operation->id, 'node_id' => $instance->node_id, 'expected_live' => null, 'legacy' => null])
            ->and($private['environment'])->toContain('APP_NAME=', '/srv/shared/database.sqlite')->not->toContain('/home/orbit-app-')
            ->and($private['routes'][0]['domain'])->toBe('private-material.test')
            ->and($private['routes'][0]['web_root'])->toBe('public')->and($private['shared_paths'])->toBe(['storage'])
            ->and(json_encode([$inputs, $operation->input], JSON_THROW_ON_ERROR))->not->toContain('APP_NAME=');
        $instance->update(['deployment_branch' => 'changed']);
        expect(fn () => $direct->workflowInputs($operation))->toThrow(ResourceOperationException::class)
            ->and(count($frames))->toBe(1);
    });

    it('admits configured shared paths on a fresh placement and still uses them for rollback', function (): void {
        $instance = appProdReleasePlacement($this->prod, 'persistent-uploads');
        $instance->update(['shared_paths' => ['uploads', 'var/media']]);
        expect(SharedPaths::forProduction($instance))->toBe(['uploads', 'var/media']);
        $deploy = app(StartInstanceDeployAction::class);
        $a = str_repeat('a', 40);
        appProdReleaseReceipt($deploy->execute($instance, 'operator', null, $a));
        appProdReleaseReceipt($deploy->execute($instance, 'operator', null, str_repeat('b', 40)));
        $rollback = app(StartInstanceRollbackAction::class)->execute($instance, substr($a, 0, 12), 'operator', null);
        $input = $rollback->input['production_release'];
        expect($input['retained'])->toBeTrue()->and($input['sha'])->toBe($a);
        $instance->refresh()->update(['shared_paths' => ['other']]);
        expect(fn () => app(DirectOperation::class)->workflowInputs($rollback))->toThrow(ResourceOperationException::class);
    });

    it('bounds best-effort results to the admitted steps and allows their full execution time', function (): void {
        $instance = appProdReleasePlacement($this->prod, 'long-steps');
        foreach (['one', 'two', 'three', 'last'] as $index => $name) {
            InstanceDeployStep::query()->create(['instance_id' => $instance->id, 'name' => $name,
                'command' => 'true', 'phase' => $name === 'last' ? 'after_activation' : 'before_activation',
                'timeout_seconds' => 900, 'position' => $index, 'required' => $name !== 'one']);
        }
        $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null, str_repeat('a', 40));
        expect($operation->input['production_release'])->toMatchArray(['build_timeout_seconds' => 5160, 'after_timeout_seconds' => 980])
            ->and($operation->input['run_budget_seconds'])->toBe(7040);
        appProdReleaseReceipt($operation, ['best_effort_steps' => [
            ['name' => 'one', 'phase' => 'before_activation', 'exit_code' => 42],
            ['name' => 'two', 'phase' => 'before_activation', 'exit_code' => 99],
            ['name' => 'one', 'phase' => 'after_activation', 'exit_code' => 1],
        ]]);
        expect(InstanceDeployment::findOrFail($operation->state['deployment_id'])->best_effort_steps)->toBe([['name' => 'one', 'exit_code' => 42]]);
    });

    it('lets automatic deploy retry capacity refusal and preserves other failed chart steps', function (): void {
        $instance = appProdReleasePlacement($this->prod, 'capacity-failure');
        $sha = str_repeat('b', 40);
        $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null, $sha);
        appProdReleaseReceipt($operation, ['live' => null], 'failed', ['detail' => 'deployment.node_capacity: release pool is full', 'failed_step' => 'Build the Instance release']);
        $row = InstanceDeployment::findOrFail($operation->state['deployment_id']);
        expect($row->error_code)->toBe('deployment.node_capacity')->and($row->failed_step)->toBe('preparation')
            ->and(AutomaticDeployFailure::isTransient($row))->toBeTrue();
        $other = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null, $sha);
        appProdReleaseReceipt($other, ['live' => null], 'failed', ['failed_step' => 'Check the Instance candidate']);
        $row = InstanceDeployment::findOrFail($other->state['deployment_id']);
        expect($row->failed_step)->toBe('Check the Instance candidate')->and(AutomaticDeployFailure::isTransient($row))->toBeFalse();
    });

    it('never falls back to host control during an uncertain first switch and restores it only with a matching receipt', function (): void {
        $sha = str_repeat('b', 40);
        $instance = appProdReleasePlacement($this->prod, 'legacy-resume', $sha);
        $deploy = app(StartInstanceDeployAction::class);
        $operation = $deploy->execute($instance, 'operator', null);
        $target = fn () => ProductionContainerRelease::target($instance->refresh());
        expect($target)->toThrow(ResourceOperationException::class);
        appProdReleaseReceipt($operation, ['live' => null, 'legacy_restored' => true, 'legacy_commit' => str_repeat('e', 40)], 'failed');
        expect($target)->toThrow(ResourceOperationException::class);
        $retry = $deploy->execute($instance, 'operator', null);
        appProdReleaseReceipt($retry, ['live' => null, 'legacy_restored' => true, 'legacy_commit' => $sha], 'failed');
        expect($target())->toBeNull()->and($instance->production_release_started_at)->toBeNull();
    });

    it('targets only the verified serving container for ordinary Process controls', function (): void {
        $instance = appProdReleasePlacement($this->prod, 'process-control');
        $sha = str_repeat('d', 40);
        appProdReleaseReceipt(app(StartInstanceDeployAction::class)->execute($instance, 'operator', null, $sha));
        $process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id,
            'name' => 'worker', 'runtime' => 'systemd', 'working_directory' => $instance->checkout_path,
            'runtime_config' => ['command' => ['php', 'artisan', 'queue:work']], 'desired_state' => 'running',
            'restart_policy' => 'always', 'status' => 'active']);
        $admission = app(ProcessOperationAdmission::class);
        $update = $admission->execute($process, 'update');
        $selected = $update->input['container'];
        expect($selected)->toMatchArray(['project' => 'orbit-releases', 'instance_id' => $instance->id,
            'node_id' => $instance->node_id, 'release' => $sha, 'name' => 'instance-'.$instance->id.'-'.substr($sha, 0, 12)]);
        $secret = app(ProcessOperationSecrets::class)->resolve($update, $process->id, '', 'installation')->value;
        expect($secret['content'])->toContain('WorkingDirectory=/srv/workspace/current', 'User=orbit');
        expect(fn () => app(StartInstanceDeployAction::class)->execute($instance, 'operator', null, str_repeat('f', 40)))->toThrow(ResourceOperationException::class);
        $stale = clone $update;
        $input = $stale->input;
        unset($input['container']);
        $stale->setAttribute('input', $input);
        expect(fn () => app(ProcessLifecycleInput::class)->forOperation($stale))->toThrow(ResourceOperationException::class);
    });

    it('binds environment sync, import and Schedule creation to the confirmed live release', function (): void {
        $sha = str_repeat('c', 40);
        $instance = appProdReleasePlacement($this->prod, 'live-controls');
        appProdReleaseReceipt(app(StartInstanceDeployAction::class)->execute($instance, 'operator', null, $sha));
        $target = ProductionContainerRelease::target($instance->refresh());
        $schedule = app(ScheduleTargetResolver::class)->placement(ScheduleTargetType::Instance, $instance->id);
        expect($schedule->container)->toBe($target)->and($schedule->workingDirectory)->toBe('/srv/workspace/current')
            ->and($schedule->user)->toBe('orbit');
        $import = app(StartInstanceEnvironmentImportAction::class)->execute($instance, true);
        $projection = app(NativeInstanceEnvironmentImportProjection::class);
        expect($projection->input($import)['intent'])->toMatchArray(['base' => '/srv/workspace/current', 'user' => 'orbit', 'production_control' => $target]);
        $import->update(['lock_key' => null, 'status' => 'succeeded']);
        $sync = app(StartInstanceEnvironmentSyncAction::class)->execute($instance);
        expect($sync->input['production_control'])->toBe($target)
            ->and(app(DirectOperation::class)->workflowFor($sync))->toBe('production-environment-sync');
        $instance->forceFill(['production_release' => ['live' => str_repeat('d', 40)]])->save();
        expect(fn () => $projection->input($import))->toThrow(ResourceOperationException::class);
    });

    it('adopts an existing checkout at the proven serving commit', function (): void {
        $serving = str_repeat('c', 40);
        $instance = appProdReleasePlacement($this->prod, 'legacy', $serving);
        $deploy = app(StartInstanceDeployAction::class);
        expect(fn () => $deploy->execute($instance, 'operator', null, str_repeat('d', 40)))->toThrow(ResourceOperationException::class);
        $operation = $deploy->execute($instance, 'operator', null);
        expect($operation->input['commit'])->toBe($serving)
            ->and($operation->input['production_release']['expected_live'])->toBeNull();
    });
});
