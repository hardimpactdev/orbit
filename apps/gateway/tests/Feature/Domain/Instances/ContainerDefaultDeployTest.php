<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Domain\Instances\Deployment\ContainerDeployOutcome;
use App\Domain\Instances\Deployment\DevelopmentDeployInput;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** The vault answers every put; without a GitHub App the origin reads as public. That is what a deployment run's start needs. */
function deployVault(): void
{
    app()->instance(WebSocketClient::class, new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        private array $frames = [];

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            if ($frame === false) {
                return [];
            }
            $payload = ValidatedData::object($frame['payload']);

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $payload['vaultName'], 'secretKey' => $payload['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    });
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
}

/** An active default Instance with a build step that would need the Node's Vite+ store in a checkout. */
function deployDefault(bool $container): Instance
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(AllocateInstanceBridgeAction::class)->execute($node);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/nckrtl/tool.git',
        'default_branch' => 'main', 'root' => '.']);
    ProjectDevelopmentDeployStep::query()->create(['project_id' => $project->id, 'name' => 'npm-build', 'position' => 1, 'command' => 'npm run build', 'required' => true, 'timeout_seconds' => 300]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/tool/default',
        'status' => InstanceState::Active]);
    if ($container) {
        InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
            'power_state' => InstanceRuntimePowerState::Stopped]);
    }

    return $instance;
}

it('deploys a container default as the container variant, whose commands need no Vite+ store on the Node', function (): void {
    $instance = deployDefault(container: true);
    $snapshot = app(InstanceDeploymentIntent::class)->snapshot($instance);
    $operation = new Operation(['input' => ['deployment' => $snapshot]]);

    expect($snapshot['container'])->toBe(app(InstanceContainer::class)->intent($instance, $instance->runtime()->firstOrFail()))
        ->and($snapshot['variant'])->toBe('container')
        ->and($snapshot['needs_vp'])->toBeFalse()
        ->and($snapshot['steps'])->toBe([['name' => 'npm-build', 'phase' => 'before_activation', 'required' => true, 'timeout_seconds' => 300,
            'command_digest' => hash('sha256', 'npm run build')]])
        ->and(InstanceDeploymentIntent::admitted($operation))->toMatchArray(['variant' => 'container', 'user' => null, 'served' => [], 'shared_paths' => []]);
});

it('keeps a checkout default on the development variant', function (): void {
    $snapshot = app(InstanceDeploymentIntent::class)->snapshot(deployDefault(container: false));

    expect($snapshot['variant'])->toBe('development')
        ->and($snapshot['container'])->toBeNull()
        ->and($snapshot['needs_vp'])->toBeTrue();
});

it('holds the Instance while a container default deploys, so a second deployment is refused', function (): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'lock_key' => OperationStore::lockKey('instance', $instance->id), 'input' => [], 'state' => [], 'sequence' => 1, 'attempts' => 0]);

    $refusal = null;
    try {
        app(StartInstanceDeployAction::class)->execute($instance, 'test', null);
    } catch (ResourceOperationException $exception) {
        $refusal = $exception->errorCode;
    }

    expect($refusal)->toBe('operation.target_busy');
});

it('runs the deployment with the container it admitted and the pinned commit, even after the Project changed', function (): void {
    deployVault();
    $instance = deployDefault(container: true);
    $snapshot = app(InstanceDeploymentIntent::class)->snapshot($instance);
    $commit = str_repeat('a', 40);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'input' => ['deployment' => $snapshot, 'commit' => $commit, 'platform' => 'linux', 'architecture' => 'x86_64',
            'development_runtime' => app(DevelopmentDeployInput::class)->capture($instance, false)], 'state' => [], 'sequence' => 1, 'attempts' => 0]);
    $instance->project->update(['default_branch' => 'next']);

    $input = app(DevelopmentDeployInput::class)->workflowInput($operation);

    $container = ValidatedData::object(ValidatedData::object($input['intent'])['container']);
    expect($container)->toBe([...ValidatedData::object($snapshot['container']), 'commit' => $commit])
        ->and($container['branch'])->toBe('main')
        ->and(array_keys($input['execution_secrets']))->toBe(['step_0', 'credential', 'process_environments'])
        ->and($input['execution_secrets']['process_environments']['key'])->toBe('development-deploy-operation-'.$operation->id.'-processes');
});

it('reads a container deployment\'s commit or refusal from the result file', function (): void {
    $run = static fn (string $status, ?string $file, array $failed = []): array => ContainerDeployOutcome::from(['status' => $status, 'result' => [
        'steps' => $file === null ? [] : ['Read the container result' => ['stdout' => $file]], 'summary' => ['failed_steps' => $failed]]]);
    $commit = str_repeat('b', 40);

    expect($run('succeeded', json_encode(['commit' => $commit], JSON_THROW_ON_ERROR))[1])->toBe(['commit' => $commit, 'failed_step' => null, 'started' => true])
        ->and($run('failed', '{"error_code":"instance.container_start_failed"}', ['Start the container'])[1]['started'])->toBeFalse()
        ->and($run('failed', '{"error_code":"deployment.checkout_dirty"}', ['Check out in the container'])[0]['error_code'])->toBe('deployment.checkout_dirty')
        ->and($run('failed', '{"error_code":"deployment.commit_not_on_branch"}', ['Fetch into the mirror'])[0]['error_code'])->toBe('deployment.commit_not_on_branch')
        ->and($run('failed', '{"commit":null}', ['Run dev deploy steps-0'])[0]['error_code'])->toBe('deployment.step_failed')
        ->and($run('failed', 'not json', ['Start the container'])[0]['error_code'])->toBe('operation.native_failed')
        ->and($run('failed', '{"error_code":"Not A Code"}', ['Start the container'])[0]['error_code'])->toBe('operation.native_failed');
});

it('records a running container but no seed when a deployment fails after the container started', function (): void {
    $instance = deployDefault(container: true);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)], 'state' => ['swamp_run_id' => 'run-1']]);

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['steps' => [ContainerDeployOutcome::ResultStep => ['stdout' => '{"error_code":"deployment.checkout_dirty"}']],
            'summary' => ['failed_steps' => ['Check out in the container']]]]);

    $runtime = InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail();
    expect($runtime->power_state)->toBe(InstanceRuntimePowerState::Running)
        ->and($runtime->seed_commit)->toBeNull()
        ->and($operation->refresh()->error_code)->toBe('deployment.checkout_dirty');
});

it('records the seed commit and a running container after a deployment passes, and leaves the checkout seed alone', function (): void {
    $instance = deployDefault(container: true);
    $commit = str_repeat('c', 40);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)], 'state' => ['swamp_run_id' => 'run-1']]);

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => [
        'steps' => ['Read the container result' => ['stdout' => json_encode(['commit' => $commit], JSON_THROW_ON_ERROR)]], 'summary' => ['failed_steps' => []]]]);

    $runtime = InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail();
    expect($runtime->seed_commit)->toBe($commit)
        ->and($runtime->power_state)->toBe(InstanceRuntimePowerState::Running)
        ->and($instance->refresh()->seed_commit)->toBeNull()
        ->and($instance->deployments()->latest('id')->firstOrFail()->commit)->toBe($commit);
});
