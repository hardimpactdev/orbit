<?php

declare(strict_types=1);

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\UpdateProjectData;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\ContainerDeployOutcome;
use App\Domain\Instances\Deployment\DevelopmentDeployInput;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Instances\NestedFleetRefresh;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** The vault answers every put; without a GitHub App the origin reads as public. That is what a deployment run's start needs. */
function deployVault(): void
{
    Http::fake(['api.github.com/repos/nckrtl/tool' => Http::response(['full_name' => 'nckrtl/tool', 'private' => false])]);
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
function deployDefault(bool $container, string $incusProject = 'orbit-instances'): Instance
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
        InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => $incusProject,
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
            'power_state' => InstanceRuntimePowerState::Stopped]);
    }

    return $instance;
}

it('deploys a container default as the container variant, whose commands need no Vite+ store on the Node', function (): void {
    $this->travelTo(now()->setTimestamp(1800000000)->microsecond(123456));
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

    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead(str_repeat('a', 40)));

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

/** @return array{head:string,files:array<string,array{mode:string,object:string,content:string}|null>} */
function deploySourceRead(string $commit, ?string $composer = null, bool $laravel = false): array
{
    $file = static fn (string $content): array => ['mode' => '100644', 'object' => str_repeat('d', 40), 'content' => $content];

    return ['head' => $commit, 'files' => ['.env.example' => $file("APP_NAME=deployed\n"),
        'composer.json' => $composer === null ? null : $file($composer), 'artisan' => $laravel ? $file('entry') : null]];
}

it('refreshes container source facts before freezing the deployed commit and its PHP runtime', function (bool $automatic, ?string $pin, ?string $previousPhp): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => $previousPhp,
        'source_profile_error' => 'app-dev.php_version_unsupported']);
    $commit = $pin ?? str_repeat('a', 40);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()
        ->with($instance->project->repository_url, ProjectSourceAccess::GitHubApp, 'main', ['.env.example', 'composer.json', 'artisan'], ...($pin === null ? [] : [$pin]))
        ->andReturn(deploySourceRead($commit, '{"require":{"php":"^8.5","laravel/framework":"^13.0"}}', true));
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);

    $operation = $automatic
        ? app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, 'source-facts', $commit)
        : app(StartInstanceDeployAction::class)->execute($instance, 'manual', 'source-facts', $pin);

    $project = $instance->project->refresh();
    expect($project)->php_version->toBe('8.5')->is_laravel->toBeTrue()->source_profile_error->toBeNull();
    expect($project->source_facts_captured_at)->not->toBeNull();
    expect($project->environmentTemplate()->firstOrFail())->source_commit->toBe($commit)
        ->entries->toBe([['key' => 'APP_NAME', 'value' => 'deployed', 'example' => 'deployed', 'removed' => false]]);
    expect($operation->input['commit'])->toBe($commit);
    $container = $operation->input['deployment']['container'];
    expect($container['php'])->toBe('/opt/orbit-runtime/current/php/8.5/bin/php');
    expect($container['environment'])->toContain('PATH=/opt/orbit-runtime/current/php/8.5/bin:/opt/orbit-runtime/current/bin:/usr/local/bin:/usr/bin:/bin');
    expect($container['units'][0]['content'])->toContain('ExecStart=/opt/orbit-runtime/current/php/8.5/bin/frankenphp');
    expect(Operation::query()->where('kind', 'instance.deploy')->count())->toBe(1);
})->with([
    'manual head replaces null facts' => [false, null, null],
    'manual pin replaces stale facts' => [false, str_repeat('b', 40), '8.4'],
    'automatic pin replaces stale facts' => [true, str_repeat('c', 40), '8.4'],
]);

it('refuses a container deployment without changing facts when source cannot be read', function (): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['php_version' => '8.4']);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()
        ->andThrow(new ResourceOperationException('project.source_unavailable', 'Could not read source.', 502));

    expect(fn () => app(StartInstanceDeployAction::class)->execute($instance, 'manual', null))
        ->toThrow(ResourceOperationException::class, 'Could not read source.');

    expect($instance->project->refresh())->php_version->toBe('8.4')->source_facts_captured_at->toBeNull();
    expect($instance->project->environmentTemplate()->exists())->toBeFalse();
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses invalid container source facts instead of deploying with the stored runtime', function (): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['php_version' => '8.4']);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()
        ->andReturn(deploySourceRead(str_repeat('a', 40), '{"require":{"php":"^7.4"}}'));
    $code = null;

    try {
        app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);
    } catch (ResourceOperationException $exception) {
        $code = $exception->errorCode;
        expect($exception->status)->toBe(422);
    }

    expect($code)->toBe('app-dev.php_version_unsupported');
    expect($instance->project->refresh())->php_version->toBe('8.4')->source_profile_error->toBeNull()->source_facts_captured_at->toBeNull();
    expect($instance->project->environmentTemplate()->exists())->toBeFalse();
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('clears obsolete PHP facts when the deployed package no longer has Composer metadata', function (): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['php_version' => '8.4', 'is_laravel' => true]);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead(str_repeat('a', 40)));

    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);

    expect($instance->project->refresh())->php_version->toBeNull()->is_laravel->toBeFalse();
    expect($operation->input['deployment']['container']['php'])->toBeNull();
    expect($operation->input['deployment']['container']['environment'])->toContain('PATH=/opt/orbit-runtime/current/bin:/usr/local/bin:/usr/bin:/bin');
});

it('keeps checkout deployment source facts and unpinned commit resolution unchanged', function (): void {
    Queue::fake();
    $instance = deployDefault(container: false);
    ProjectDevelopmentDeployStep::query()->where('project_id', $instance->project_id)->delete();
    $instance->project->update(['php_version' => '8.4']);
    $this->mock(ProjectSourceReader::class)->shouldNotReceive('read');

    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);

    expect($instance->project->refresh())->php_version->toBe('8.4')->source_facts_captured_at->toBeNull();
    expect($operation->input)->not->toHaveKey('commit');
    expect($operation->input['deployment']['variant'])->toBe('development');
});

it('skips source capture for an automatic checkout default deployment', function (): void {
    Queue::fake();
    $instance = deployDefault(container: false);
    ProjectDevelopmentDeployStep::query()->where('project_id', $instance->project_id)->delete();
    $instance->project->update(['php_version' => '8.4']);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $this->mock(ProjectSourceReader::class)->shouldNotReceive('read');
    $commit = str_repeat('a', 40);

    $operation = app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, $commit);

    expect($operation->input['deployment']['variant'])->toBe('development');
    expect($operation->input['commit'])->toBe($commit);
    expect($instance->project->refresh())->php_version->toBe('8.4')->source_facts_captured_at->toBeNull();
    expect($instance->project->environmentTemplate()->exists())->toBeFalse();
});

it('skips source capture for a production deployment', function (bool $automatic): void {
    Queue::fake();
    $instance = deployDefault(container: false);
    $instance->node->roles()->where('role', RoleName::AppDev)->delete();
    $instance->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $home = '/home/orbit-app-'.$instance->project_id;
    $instance->update(['branch' => 'main', 'production_user' => 'orbit-app-'.$instance->project_id,
        'production_home' => $home, 'checkout_path' => $home.'/releases/initial', 'provisioning_step' => 'active',
        'source_is_laravel' => false, 'auto_deploy' => true]);
    InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'env_key' => 'APP_NAME', 'env_value' => 'production']);
    $instance->project->update(['php_version' => '8.4']);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $this->mock(ProjectSourceReader::class)->shouldNotReceive('read');
    $commit = str_repeat('a', 40);

    $operation = $automatic
        ? app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, $commit)
        : app(StartInstanceDeployAction::class)->execute($instance, 'manual', null, $commit);

    expect($operation->input['deployment']['variant'])->toBe('production');
    expect($operation->input['commit'])->toBe($commit);
    expect($instance->project->refresh())->php_version->toBe('8.4')->source_facts_captured_at->toBeNull();
    expect($instance->project->environmentTemplate()->exists())->toBeFalse();
})->with(['manual' => false, 'automatic' => true]);

it('replays a container admission without reading a newer branch head or replacing its facts', function (): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead(str_repeat('a', 40)));
    $start = fn (): Operation => app(OperationIdempotency::class)->under('instance.deploy', 'same-deploy', 'same-input',
        fn (): Operation => app(StartInstanceDeployAction::class)->execute($instance, 'manual', null));
    $first = $start();

    $replay = $start();

    expect($replay->id)->toBe($first->id);
    expect($replay->input['commit'])->toBe(str_repeat('a', 40));
    expect(Operation::query()->count())->toBe(1);
});

it('refuses a web container without PHP at the deployed commit and preserves its previous facts', function (): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.4']);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead(str_repeat('a', 40)));
    $code = null;

    try {
        app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);
    } catch (ResourceOperationException $exception) {
        $code = $exception->errorCode;
    }

    expect($code)->toBe('project.source_defaults_incomplete');
    expect($instance->project->refresh())->php_version->toBe('8.4')->source_facts_captured_at->toBeNull();
    expect($instance->project->environmentTemplate()->exists())->toBeFalse();
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses an unsettled move before reading deploy source', function (string $entry, string $status): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['php_version' => '8.4']);
    $move = Operation::query()->create(['kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => $status, 'input' => [], 'result' => ['moved' => false]]);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $this->mock(ProjectSourceReader::class)->shouldNotReceive('read');
    $commit = str_repeat('a', 40);
    $refusal = null;

    try {
        $action = app(StartInstanceDeployAction::class);
        match ($entry) {
            'manual' => $action->execute($instance, 'manual', null),
            'pinned' => $action->pinned($instance, 'manual', null, $commit),
            'automatic' => $action->automatic($instance, $instance->node, null, $commit),
        };
    } catch (ResourceOperationException $exception) {
        $refusal = $exception->errorCode;
        expect($exception->status)->toBe(409);
    }

    expect($refusal)->toBe('instance.lifecycle_busy');
    expect($instance->project->refresh())->php_version->toBe('8.4')->source_facts_captured_at->toBeNull();
    expect($instance->project->environmentTemplate()->exists())->toBeFalse();
    expect(Operation::query()->where('kind', 'instance.deploy')->exists())->toBeFalse();
    expect($move->refresh()->status->value)->toBe($status);
    Queue::assertNothingPushed();
})->with(['manual', 'pinned', 'automatic'])->with(['running', 'failed']);

it('rechecks the move during admission if it started while deploy source was read', function (string $entry): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['php_version' => '8.4']);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $commit = str_repeat('a', 40);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturnUsing(static function () use ($instance, $commit): array {
        Operation::query()->create(['kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'status' => OperationStatus::Running, 'input' => []]);

        return deploySourceRead($commit, '{"require":{"php":"^8.5"}}');
    });
    $refusal = null;

    try {
        $action = app(StartInstanceDeployAction::class);
        match ($entry) {
            'manual' => $action->execute($instance, 'manual', null),
            'pinned' => $action->pinned($instance, 'manual', null, $commit),
            'automatic' => $action->automatic($instance, $instance->node, null, $commit),
        };
    } catch (ResourceOperationException $exception) {
        $refusal = $exception->errorCode;
        expect($exception->status)->toBe(409);
    }

    expect($refusal)->toBe('instance.lifecycle_busy');
    expect($instance->project->refresh())->php_version->toBe('8.4')->source_facts_captured_at->toBeNull();
    expect($instance->project->environmentTemplate()->exists())->toBeFalse();
    expect(Operation::query()->where('kind', 'instance.deploy')->exists())->toBeFalse();
    expect(Operation::query()->where('kind', 'instance.migrate')->count())->toBe(1);
    Queue::assertNothingPushed();
})->with(['manual', 'pinned', 'automatic']);

it('reads deploy source before opening either admission transaction', function (bool $automatic): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $baseline = DB::transactionLevel();
    $commit = str_repeat('a', 40);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturnUsing(static function () use ($baseline, $commit): array {
        // LazilyRefreshDatabase owns the baseline transaction; admission must add none during network/Git work.
        expect(DB::transactionLevel())->toBe($baseline);

        return deploySourceRead($commit);
    });

    $operation = $automatic
        ? app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, $commit)
        : app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);

    expect($operation->input['commit'])->toBe($commit);
})->with(['manual' => false, 'automatic' => true]);

it('refuses changed source configuration after reading but before recording deploy facts', function (bool $automatic, string $field, mixed $changed): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['php_version' => '8.4']);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $commit = str_repeat('a', 40);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturnUsing(static function () use ($instance, $field, $changed, $commit): array {
        Project::query()->whereKey($instance->project_id)->update([$field => $changed]);

        return deploySourceRead($commit, '{"require":{"php":"^8.5"}}');
    });
    $refusal = null;

    try {
        if ($automatic) {
            app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, $commit);
        } else {
            app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);
        }
    } catch (ResourceOperationException $exception) {
        $refusal = $exception->errorCode;
    }

    expect($refusal)->toBe('deployment.source_changed');
    expect($instance->project->refresh())->php_version->toBe('8.4')->source_facts_captured_at->toBeNull();
    expect($instance->project->environmentTemplate()->exists())->toBeFalse();
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['manual' => false, 'automatic' => true])->with([
    'repository URL' => ['repository_url', 'https://github.com/nckrtl/other.git'],
    'source access' => ['source_access', ProjectSourceAccess::GhCli->value],
    'default branch' => ['default_branch', 'next'],
    'root' => ['root', 'apps/site/public'],
    'type' => ['type', ProjectType::LaravelPackage->value],
]);

it('admits its captured facts after a real project refresh with unchanged source settings', function (bool $automatic): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $commit = str_repeat('a', 40);
    $updatedHead = str_repeat('b', 40);
    $reader = $this->mock(ProjectSourceReader::class);
    $reader->shouldReceive('read')->once()->with($instance->project->repository_url, ProjectSourceAccess::GitHubApp, 'main',
        ['.env.example', 'composer.json', 'artisan'])->andReturn(deploySourceRead($updatedHead, '{"require":{"php":"~8.4.0"}}'));
    $reader->shouldReceive('read')->once()->with($instance->project->repository_url, ProjectSourceAccess::GitHubApp, 'main',
        ['.env.example', 'composer.json', 'artisan'], $commit)->andReturnUsing(static function () use ($instance, $commit, $updatedHead): array {
            $read = deploySourceRead($commit, '{"require":{"php":"^8.5"}}');
            $updated = app(UpdateProjectAction::class)->execute($instance->project->fresh(), new UpdateProjectData(
                false, null, false, null, false, null, false, null, false, null,
                sourceAccessProvided: true, sourceAccess: ProjectSourceAccess::GitHubApp,
            ));
            expect($updated)->type->toBe(ProjectType::NodePackage)->php_version->toBe('8.4')->source_facts_captured_at->not->toBeNull();
            expect($updated->environmentTemplate()->firstOrFail()->source_commit)->toBe($updatedHead);

            return $read;
        });

    $operation = $automatic
        ? app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, $commit)
        : app(StartInstanceDeployAction::class)->execute($instance, 'manual', null, $commit);

    expect($operation->input['commit'])->toBe($commit);
    expect($operation->input['deployment']['container']['php'])->toBe('/opt/orbit-runtime/current/php/8.5/bin/php');
    expect($instance->project->refresh())->type->toBe(ProjectType::NodePackage)->php_version->toBe('8.5');
    expect($instance->project->environmentTemplate()->firstOrFail()->source_commit)->toBe($commit);
    expect(Operation::query()->count())->toBe(1);
})->with(['manual' => false, 'automatic' => true]);

it('refuses admission after a real project update changes source settings and preserves that update', function (bool $automatic): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $commit = str_repeat('a', 40);
    $updatedHead = str_repeat('b', 40);
    $reader = $this->mock(ProjectSourceReader::class);
    $reader->shouldReceive('read')->once()->with($instance->project->repository_url, ProjectSourceAccess::GitHubApp, 'main',
        ['.env.example', 'composer.json', 'artisan'])->andReturn(deploySourceRead($updatedHead, '{"require":{"php":"~8.4.0"}}'));
    $reader->shouldReceive('read')->once()->with($instance->project->repository_url, ProjectSourceAccess::GitHubApp, 'main',
        ['.env.example', 'composer.json', 'artisan'], $commit)->andReturnUsing(static function () use ($instance, $commit, $updatedHead): array {
            $read = deploySourceRead($commit, '{"require":{"php":"^8.5"}}');
            $updated = app(UpdateProjectAction::class)->execute($instance->project->fresh(), new UpdateProjectData(
                true, ProjectType::LaravelPackage, false, null, false, null, false, null, false, null,
                sourceAccessProvided: true, sourceAccess: ProjectSourceAccess::GitHubApp,
            ));
            expect($updated)->type->toBe(ProjectType::LaravelPackage)->php_version->toBe('8.4')->source_facts_captured_at->not->toBeNull();
            expect($updated->environmentTemplate()->firstOrFail()->source_commit)->toBe($updatedHead);

            return $read;
        });
    $refusal = null;

    try {
        if ($automatic) {
            app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, $commit);
        } else {
            app(StartInstanceDeployAction::class)->execute($instance, 'manual', null, $commit);
        }
    } catch (ResourceOperationException $exception) {
        $refusal = $exception->errorCode;
        expect($exception->status)->toBe(409);
    }

    expect($refusal)->toBe('deployment.source_changed');
    expect($instance->project->refresh())->type->toBe(ProjectType::LaravelPackage)->php_version->toBe('8.4');
    expect($instance->project->environmentTemplate()->firstOrFail()->source_commit)->toBe($updatedHead);
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['manual' => false, 'automatic' => true]);

it('rechecks automatic eligibility after reading source', function (): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $commit = str_repeat('a', 40);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturnUsing(static function () use ($instance, $commit): array {
        $instance->node->roles()->where('role', RoleName::AppDev)->update(['status' => 'failed']);

        return deploySourceRead($commit);
    });
    $refusal = null;

    try {
        app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, $commit);
    } catch (ResourceOperationException $exception) {
        $refusal = $exception->errorCode;
    }

    expect($refusal)->toBe(AutomaticDeployPolicyWriter::InactiveRole);
    expect($instance->project->refresh()->source_facts_captured_at)->toBeNull();
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('replays an admission that appeared during source capture without overwriting its facts', function (bool $automatic): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['php_version' => '8.4']);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $commit = str_repeat('a', 40);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturnUsing(static function () use ($instance, $commit): array {
        Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'status' => OperationStatus::Queued, 'idempotency_key' => 'concurrent-deploy',
            'idempotency_fingerprint' => 'same-input', 'input' => ['commit' => $commit], 'sequence' => 1]);

        return deploySourceRead($commit, '{"require":{"php":"^8.5"}}');
    });

    $operation = app(OperationIdempotency::class)->under('instance.deploy', 'concurrent-deploy', 'same-input', fn (): Operation => $automatic
        ? app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, $commit)
        : app(StartInstanceDeployAction::class)->execute($instance, 'manual', null));

    expect($operation->idempotency_key)->toBe('concurrent-deploy');
    expect($instance->project->refresh())->php_version->toBe('8.4')->source_facts_captured_at->toBeNull();
    expect(Operation::query()->count())->toBe(1);
    Queue::assertNothingPushed();
})->with(['manual' => false, 'automatic' => true]);

it('retains admission-wide PHP facts after deployment failure or cancellation while keeping the older seed', function (string $status): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['php_version' => '8.4']);
    $seed = str_repeat('a', 40);
    $commit = str_repeat('b', 40);
    $instance->runtime()->update(['seed_commit' => $seed]);
    ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'worker', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'php8.5', 'worker.php'], 'environment' => [], 'restart_policy' => 'always']]);
    app(InstantiateProjectRuntimeDefinitionsAction::class)->refreshForContainer($instance);
    expect(app(ContainerProcesses::class)->units($instance)[0]['content'])->toContain('Environment=PATH=/opt/orbit-runtime/current/php/8.4/bin:');
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead($commit, '{"require":{"php":"^8.5"}}'));
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'facts-run']]);

    app(InstanceDeployProjection::class)->apply($operation, ['status' => $status, 'run_id' => 'facts-run',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => [
            'steps' => [], 'summary' => ['failed_steps' => ['Run dev deploy steps-0']]]]);

    expect($operation->refresh()->status->value)->toBe($status);
    expect($instance->project->refresh()->php_version)->toBe('8.5');
    expect($instance->project->environmentTemplate()->firstOrFail()->source_commit)->toBe($commit);
    expect($instance->runtime()->firstOrFail()->seed_commit)->toBe($seed);
    $snapshot = app(InstanceDeploymentIntent::class)->snapshot($instance);
    expect($snapshot['container']['php'])->toBe('/opt/orbit-runtime/current/php/8.5/bin/php');
    expect(app(ContainerProcesses::class)->units($instance)[0]['content'])->toContain('Environment=PATH=/opt/orbit-runtime/current/php/8.5/bin:');
    $clone = Instance::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'name' => 'feature', 'checkout_path' => '/srv/tool/feature', 'status' => InstanceState::Active]);
    $runtime = InstanceRuntime::query()->create(['instance_id' => $clone->id, 'node_id' => $clone->node_id,
        'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$clone->id, 'bridge_address' => '10.253.0.3',
        'runtime_version' => 'r', 'agent_version' => 'a', 't3_port' => 3800, 'clone_source_instance_id' => $instance->id,
        'power_state' => InstanceRuntimePowerState::Stopped]);
    $cloneIntent = app(InstanceContainer::class)->intent($clone, $runtime);
    expect($cloneIntent['clone']['seed_commit'])->toBe($seed);
    expect($cloneIntent['php'])->toBe('/opt/orbit-runtime/current/php/8.5/bin/php');
})->with(['failed', 'cancelled']);

it('refuses a real pending routed container projection with 409 before recording a deployment', function (): void {
    Queue::fake();
    $instance = deployDefault(container: true);
    $instance->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'default.tool.test', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(
        deploySourceRead(str_repeat('a', 40), '{"require":{"php":"^8.5","laravel/framework":"^13.0"}}', true));

    expect(fn () => app(StartInstanceDeployAction::class)->execute($instance, 'manual', null))
        ->toThrow(fn (ResourceOperationException $error) => expect([$error->errorCode, $error->status])->toBe(['deployment.projection_pending', 409]));

    $this->assertDatabaseHas('instances', ['id' => $instance->id, 'development_projection_pending' => true]);
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

/** Makes a container default the nested-fleet default: its Project flag, its runtime in the nested project and the Node switch. */
function nestedFleetDefault(bool $flag = true, bool $switch = true, string $incusProject = InstancePlacement::NestedProject): Instance
{
    $instance = deployDefault(container: true, incusProject: $incusProject);
    $instance->project->forceFill(['nested_fleet' => $flag, 'repository_identity' => GitRepositoryIdentity::derive($instance->project->repository_url)])->save();
    $instance->node->update(['settings' => [...($instance->node->settings ?? []), 'nested_fleet' => $switch]]);

    return $instance->refresh();
}

it('gives only a nested-fleet default on a switched-on Node the fleet of its deployment', function (bool $flag, bool $switch, string $incusProject, bool $fleet): void {
    $instance = nestedFleetDefault($flag, $switch, $incusProject);

    $snapshot = app(InstanceDeploymentIntent::class)->snapshot($instance);

    expect(array_key_exists('fleet', $snapshot))->toBe($fleet);
    if ($fleet) {
        expect($snapshot['fleet'])->toBe([
            'guests' => ['gateway' => 'nested-gateway', 'app-dev' => 'nested-app-dev', 'app-prod' => 'nested-app-prod'],
            'layout' => ['gateway' => ['address' => '10.232.2.11'], 'app-dev' => ['address' => '10.232.2.12'], 'app-prod' => ['address' => '10.232.2.13']],
            'operator' => ['address' => '10.45.0.10', 'hub' => '10.45.0.1', 'network' => '10.45.0.0/24'],
            'checkout' => ['path' => '/home/orbit/orbit'],
            'proxy' => ['HTTP_PROXY' => 'http://10.253.0.1:3128', 'HTTPS_PROXY' => 'http://10.253.0.1:3128', 'http_proxy' => 'http://10.253.0.1:3128',
                'https_proxy' => 'http://10.253.0.1:3128', 'NO_PROXY' => 'localhost,127.0.0.1,.orbit,10.45.0.0/24,10.232.2.0/24',
                'no_proxy' => 'localhost,127.0.0.1,.orbit,10.45.0.0/24,10.232.2.0/24'],
        ]);
    }
})->with([
    'nested-fleet default' => [true, true, InstancePlacement::NestedProject, true],
    'switch off' => [true, false, InstancePlacement::NestedProject, false],
    'flag off' => [false, true, InstancePlacement::NestedProject, false],
    'normal project runtime' => [true, true, 'orbit-instances', false],
    'normal Project' => [false, false, 'orbit-instances', false],
]);

it('keeps a normal container deployment\'s workflow intent and budget free of the fleet', function (): void {
    deployVault();
    $instance = deployDefault(container: true);
    Queue::fake();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead(str_repeat('a', 40)));

    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);
    $operation->update(['input' => [...$operation->input, 'architecture' => 'x86_64', 'development_runtime' => app(DevelopmentDeployInput::class)->capture($instance, false)]]);
    $intent = ValidatedData::object(app(DevelopmentDeployInput::class)->workflowInput($operation)['intent']);

    expect(array_key_exists('fleet', $intent))->toBeFalse()
        ->and(array_key_exists('fleet', $operation->input['deployment']))->toBeFalse()
        ->and($operation->input['run_budget_seconds'])->toBe(900 + 300);
});

it('hands the fleet to the run of a nested-fleet default and widens its budget', function (): void {
    deployVault();
    $instance = nestedFleetDefault();
    Queue::fake();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead(str_repeat('a', 40)));

    $operation = app(StartInstanceDeployAction::class)->pinned($instance, NestedFleetRefresh::Trigger, null, str_repeat('a', 40));
    $operation->update(['input' => [...$operation->input, 'architecture' => 'x86_64', 'development_runtime' => app(DevelopmentDeployInput::class)->capture($instance, false)]]);
    $intent = ValidatedData::object(app(DevelopmentDeployInput::class)->workflowInput($operation)['intent']);

    expect($intent['fleet'])->toBe($operation->input['deployment']['fleet'])
        ->and($operation->input['run_budget_seconds'])->toBe(900 + 300 + NestedFleetIntent::RunSeconds);
});

it('refuses the refresh of a nested-fleet default while a clone of it is being created', function (string $status, bool $refused): void {
    $instance = nestedFleetDefault();
    $clone = Instance::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'name' => 'feature', 'checkout_path' => '/srv/tool/feature', 'status' => InstanceState::Active]);
    InstanceRuntime::query()->create(['instance_id' => $clone->id, 'node_id' => $clone->node_id, 'incus_project' => InstancePlacement::NestedProject,
        'container_name' => 'instance-'.$clone->id, 'bridge_address' => '10.253.0.3', 'runtime_version' => 'r', 'agent_version' => 'a',
        't3_port' => 3800, 'clone_source_instance_id' => $instance->id, 'power_state' => InstanceRuntimePowerState::Stopped]);
    Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $clone->id, 'node_id' => $clone->node_id,
        'status' => $status, 'input' => [], 'state' => [], 'sequence' => 1, 'attempts' => 0]);

    $snapshot = fn (): array => app(InstanceDeploymentIntent::class)->snapshot($instance);

    if ($refused) {
        expect($snapshot)->toThrow(fn (ResourceOperationException $error) => expect([$error->errorCode, $error->status])->toBe(['instance.clone_in_flight', 409]));
    } else {
        expect($snapshot()['fleet']['checkout'])->toBe(['path' => '/home/orbit/orbit']);
    }
})->with([
    'running copy' => ['running', true],
    'queued copy' => ['queued', true],
    'finished copy' => ['succeeded', false],
]);

it('names the failed fleet step and its code, and keeps the code of the failed phase after a restore', function (): void {
    $run = static fn (string $file, array $failed): array => ContainerDeployOutcome::from(['status' => 'failed', 'result' => [
        'steps' => ['Read the container result' => ['stdout' => $file]], 'summary' => ['failed_steps' => $failed]]]);

    [$data, $payload] = $run('{"error_code":"instance.container_fleet_refresh_failed"}', ['Refresh the inner fleet']);
    expect($data['error_code'])->toBe('instance.container_fleet_refresh_failed')
        ->and($payload['failed_step'])->toBe('Refresh the inner fleet')
        ->and($payload['started'])->toBeTrue();
    [$data, $payload] = $run('{"error_code":"instance.container_fleet_start_failed"}', ['Start the inner fleet', 'Restore the old seed']);
    expect($data['error_code'])->toBe('instance.container_fleet_start_failed')->and($payload['failed_step'])->toBe('Start the inner fleet');
});

it('admits one refresh per nested-fleet default to the release commit, and nothing without one', function (): void {
    Queue::fake();
    config(['instances.nested_fleet_refresh' => true]);
    expect(app(NestedFleetRefresh::class)->admitAll(str_repeat('a', 40)))->toBe([]);
    $this->artisan('instances:refresh-nested-fleets', ['--commit' => str_repeat('a', 40)])
        ->expectsOutput('No nested-fleet default on a switched-on Node: nothing to refresh.')->assertExitCode(0);

    $instance = nestedFleetDefault();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')
        ->with($instance->project->repository_url, ProjectSourceAccess::GitHubApp, 'main', ['.env.example', 'composer.json', 'artisan'], str_repeat('a', 40))
        ->twice()->andReturn(deploySourceRead(str_repeat('a', 40)));

    $first = app(NestedFleetRefresh::class)->admitAll(str_repeat('a', 40));
    $operation = Operation::query()->where('kind', 'instance.deploy')->sole();
    expect($first)->toBe(['tool/default on beast' => 'operation:'.$operation->id])
        ->and($operation->input['commit'])->toBe(str_repeat('a', 40))
        ->and($operation->triggered_by)->toBe(NestedFleetRefresh::Trigger)
        ->and(app(NestedFleetRefresh::class)->admitAll(str_repeat('a', 40)))->toBe(['tool/default on beast' => 'operation.target_busy'])
        ->and(Operation::query()->where('kind', 'instance.deploy')->count())->toBe(1);
});

it('reports a refused refresh and still ends the release step with success', function (): void {
    Queue::fake();
    config(['instances.nested_fleet_refresh' => true]);
    nestedFleetDefault();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()
        ->andThrow(new ResourceOperationException('deployment.commit_unavailable', 'The commit is not in the repository.', 409));

    $this->artisan('instances:refresh-nested-fleets', ['--commit' => str_repeat('a', 40)])
        ->expectsOutput('tool/default on beast: deployment.commit_unavailable')->assertExitCode(0);
    expect(Operation::query()->where('kind', 'instance.deploy')->count())->toBe(0);
    $this->artisan('instances:refresh-nested-fleets', ['--commit' => 'main'])->assertExitCode(2);
});

it('reports an admission that fails with any other error as one line, and still ends with success', function (): void {
    Queue::fake();
    config(['instances.nested_fleet_refresh' => true]);
    nestedFleetDefault();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andThrow(new RuntimeException('network'));

    $this->artisan('instances:refresh-nested-fleets', ['--commit' => str_repeat('a', 40)])
        ->expectsOutput('tool/default on beast: '.NestedFleetRefresh::AdmissionFailed)->assertExitCode(0);
});

it('admits and reads nothing while the nested-fleet refresh is off, and admits once it is on', function (): void {
    Queue::fake();
    nestedFleetDefault();
    config(['instances.nested_fleet_refresh' => false]);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead(str_repeat('a', 40)));

    expect(app(NestedFleetRefresh::class)->admitAll(str_repeat('a', 40)))->toBeNull();
    $this->artisan('instances:refresh-nested-fleets', ['--commit' => str_repeat('a', 40)])
        ->expectsOutput('The nested-fleet refresh is off (ORBIT_INSTANCES_NESTED_FLEET_REFRESH): nothing to refresh.')->assertExitCode(0);
    expect(Operation::query()->where('kind', 'instance.deploy')->count())->toBe(0);

    config(['instances.nested_fleet_refresh' => true]);
    $this->artisan('instances:refresh-nested-fleets', ['--commit' => str_repeat('a', 40)])->assertExitCode(0);
    expect(Operation::query()->where('kind', 'instance.deploy')->count())->toBe(1);
});
