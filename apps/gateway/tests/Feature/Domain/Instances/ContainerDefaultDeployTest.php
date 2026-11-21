<?php

declare(strict_types=1);

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Operations\FailUnreadableResultAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Data\Instances\InstanceDeploymentData;
use App\Data\Projects\UpdateProjectData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCommitComparison;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\ContainerDeployOutcome;
use App\Domain\Instances\Deployment\DevelopmentDeployInput;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
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
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

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
            'power_state' => InstanceRuntimePowerState::Stopped, 'idmap_size' => $incusProject === InstancePlacement::NestedProject ? 101000000 : null]);
    }

    return $instance;
}

it('deploys a container default as the container variant, whose commands need no Vite+ store on the Node', function (): void {
    $this->travelTo(now()->setTimestamp(1800000000)->microsecond(123456));
    $instance = deployDefault(container: true);
    $snapshot = app(InstanceDeploymentIntent::class)->snapshot($instance);
    $operation = new Operation(['input' => ['deployment' => $snapshot]]);

    expect(array_diff_key($snapshot['container'], ['seed_files' => true]))->toBe(app(InstanceContainer::class)->intent($instance, $instance->runtime()->firstOrFail()))
        ->and($snapshot['variant'])->toBe('container')
        ->and($snapshot['needs_vp'])->toBeFalse()
        ->and($snapshot['steps'])->toBe([['name' => 'npm-build', 'phase' => 'before_activation', 'required' => true, 'timeout_seconds' => 300,
            'command_digest' => hash('sha256', 'npm run build'), 'inputs' => []]])
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
        // A start or resume of a sleeping default removes this marker once the app answers, so the Route serves the app again.
        ->and(ValidatedData::object($input['intent'])['asleep_marker'])->toBe('/data/caddy/orbit/hibernation/app-instance-'.$instance->id.'.asleep')
        ->and(array_keys($input['execution_secrets']))->toBe(['step_0', 'credential', 'process_environments'])
        ->and($input['execution_secrets']['process_environments']['key'])->toBe('development-deploy-operation-'.$operation->id.'-processes');
});

it('reads a container deployment\'s commit or refusal from the result file', function (): void {
    $run = static fn (string $status, ?string $file, array $failed = []): array => ContainerDeployOutcome::from(['status' => $status, 'result' => [
        'steps' => $file === null ? [] : ['Read the container result' => ['stdout' => $file]], 'summary' => ['failed_steps' => $failed]]]);
    $commit = str_repeat('b', 40);

    expect($run('succeeded', json_encode(['commit' => $commit], JSON_THROW_ON_ERROR))[1])->toBe(['commit' => $commit, 'failed_step' => null, 'started' => true, 'club_key' => null])
        ->and($run('failed', '{"error_code":"instance.container_start_failed","failed_phase":"start"}', ['Prepare the container'])[1]['started'])->toBeFalse()
        ->and($run('failed', '{"error_code":"deployment.checkout_dirty"}', ['Check out in the container'])[0]['error_code'])->toBe('deployment.checkout_dirty')
        ->and($run('failed', '{"error_code":"deployment.commit_not_on_branch","failed_phase":"fetch"}', ['Prepare the container'])[0]['error_code'])->toBe('deployment.commit_not_on_branch')
        ->and($run('failed', '{"commit":null}', ['Run dev deploy steps-0'])[0]['error_code'])->toBe('deployment.step_failed')
        ->and($run('failed', 'not json', ['Prepare the container'])[0]['error_code'])->toBe('operation.native_failed')
        ->and($run('failed', '{"error_code":"Not A Code"}', ['Prepare the container'])[0]['error_code'])->toBe('operation.native_failed');
    // "Prepare the container" starts the container in its first phase: the container runs when `start` passed, also when a later phase
    // was killed before it wrote a failure (no `failed_phase`); a result without a passed start leaves the power state as it was.
    expect($run('failed', '{"error_code":"deployment.commit_not_on_branch","failed_phase":"fetch","phases_passed":["start"]}', ['Prepare the container'])[1]['started'])->toBeTrue()
        ->and($run('failed', '{"error_code":"instance.container_fleet_drift","failed_phase":"fleet_start","phases_passed":["start"]}', ['Prepare the container'])[1]['started'])->toBeTrue()
        ->and($run('failed', '{"commit":null,"club_key":"changed","phases_passed":["start"]}', ['Prepare the container'])[1])->toMatchArray(['started' => true, 'club_key' => 'changed'])
        ->and($run('failed', '{"commit":null,"club_key":"changed","phases_passed":["start"]}', ['Prepare the container'])[0]['error_code'])->toBe('operation.native_failed')
        ->and($run('failed', '{"error_code":"instance.container_start_failed","failed_phase":"start","phases_passed":[]}', ['Prepare the container'])[1]['started'])->toBeFalse()
        ->and($run('failed', 'not json', ['Prepare the container'])[1]['started'])->toBeFalse()
        ->and($run('failed', '{"error_code":"instance.container_processes_failed","failed_phase":"processes"}', ['Finish the container'])[1]['started'])->toBeTrue()
        ->and($run('failed', 'not json', ['Write the container intent'])[1]['started'])->toBeFalse();
    // A Process that did not become active is named in the failure and in the payload.
    [$data, $payload] = $run('failed', '{"error_code":"instance.container_processes_failed","detail":"Not active: orbit-process-9-mailer.service","units_inactive":["orbit-process-9-mailer.service"]}',
        ['Finish the container']);
    expect($data['failure']['detail'])->toBe('Not active: orbit-process-9-mailer.service')
        ->and($payload['units_inactive'])->toBe(['orbit-process-9-mailer.service']);
});

it('records the exit code of each best-effort step that ran, never its output, and the deployment still passes', function (): void {
    $admitted = [['name' => 'npm-build', 'required' => true], ['name' => 'tia-warm', 'required' => false], ['name' => 'cache-warm', 'required' => false],
        ['name' => 'lint-warm', 'required' => false], ['name' => 'docs-warm', 'required' => false]];
    $run = static fn (string $status, array $steps, array $failed = []): array => ContainerDeployOutcome::from(['status' => $status, 'result' => [
        'steps' => [ContainerDeployOutcome::ResultStep => ['stdout' => '{"commit":"'.str_repeat('b', 40).'"}'], ...$steps], 'summary' => ['failed_steps' => $failed]]], [], $admitted);

    // The wrapper prints only the command's exit code: 1 is a failed warm-up, 0 a passed one, 124 a timeout. A required step prints nothing.
    [$data, $payload] = $run('succeeded', ['Run dev deploy steps-0-0' => ['status' => 'succeeded', 'stdout' => ''],
        'Run dev deploy steps-1-1' => ['status' => 'succeeded', 'stdout' => "1\n"], 'Run dev deploy steps-2-2' => ['status' => 'succeeded', 'stdout' => "0\n"],
        'Run dev deploy steps-3-3' => ['status' => 'succeeded', 'stdout' => "124\n"], 'Run dev deploy steps-4-4' => ['status' => 'skipped']]);
    expect($data)->not->toHaveKey('error_code')
        ->and($payload['commit'])->toBe(str_repeat('b', 40))
        ->and($payload['best_effort_steps'])->toBe([['name' => 'tia-warm', 'exit_code' => 1], ['name' => 'cache-warm', 'exit_code' => 0], ['name' => 'lint-warm', 'exit_code' => 124]]);
    // Anything but an integer from 0 to 255 is null; the line itself is never kept.
    foreach (['SQLSTATE[HY000] [1044] Access denied for user', '256', '-1', '01', '1 2', ''] as $line) {
        $payload = $run('succeeded', ['Run dev deploy steps-1-1' => ['status' => 'succeeded', 'stdout' => $line]])[1];
        expect($payload['best_effort_steps'])->toBe([['name' => 'tia-warm', 'exit_code' => null]])
            ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('SQLSTATE');
    }
    // A failed required step still fails the deployment; the best-effort steps before it keep their codes.
    [$data, $payload] = $run('failed', ['Run dev deploy steps-0-0' => ['status' => 'failed'], 'Run dev deploy steps-1-1' => ['status' => 'succeeded', 'stdout' => '1']],
        ['Run dev deploy steps-0-0']);
    expect($data['error_code'])->toBe('deployment.step_failed')
        ->and($payload['failed_step'])->toBe('Run dev deploy steps-0-0')
        ->and($payload['best_effort_steps'])->toBe([['name' => 'tia-warm', 'exit_code' => 1]]);
    // A deployment whose steps are all required, or that ran none, has no key.
    expect($run('succeeded', ['Run dev deploy steps-0-0' => ['status' => 'succeeded', 'stdout' => '']])[1])->not->toHaveKey('best_effort_steps');
});

it('stores a failed best-effort step\'s exit code in the Operation result and the deployment record of a passed deployment', function (): void {
    $instance = deployDefault(container: true);
    ProjectDevelopmentDeployStep::query()->create(['project_id' => $instance->project_id, 'name' => 'tia-warm', 'position' => 2, 'command' => 'composer test',
        'required' => false, 'timeout_seconds' => 120]);
    $commit = str_repeat('c', 40);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)], 'state' => ['swamp_run_id' => 'run-1']]);

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => [
        'steps' => ['Read the container result' => ['stdout' => json_encode(['commit' => $commit], JSON_THROW_ON_ERROR)],
            'Run dev deploy steps-0-0' => ['status' => 'succeeded', 'stdout' => ''], 'Run dev deploy steps-1-1' => ['status' => 'succeeded', 'stdout' => "1\n"]],
        'summary' => ['failed_steps' => []]]]);

    $operation->refresh();
    $row = InstanceDeployment::query()->findOrFail($operation->state['deployment_id']);
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result['best_effort_steps'])->toBe([['name' => 'tia-warm', 'exit_code' => 1]])
        ->and($row->status)->toBe('succeeded')
        ->and($row->best_effort_steps)->toBe([['name' => 'tia-warm', 'exit_code' => 1]])
        // instance:deployment:show returns the record's data.
        ->and(InstanceDeploymentData::fromModel($row)->toArray()['best_effort_steps'])->toBe([['name' => 'tia-warm', 'exit_code' => 1]]);
});

it('records a container deployment with the Operation\'s start, end and duration and how each deploy step ended', function (string $status, array $ended, array $events): void {
    $instance = deployDefault(container: true);
    // `composer-install` watches its inputs, and `tia-warm` is best-effort.
    foreach ([['composer-install', 2, true, ['composer.json', 'composer.lock']], ['tia-warm', 3, false, []], ['migrate', 4, true, []]] as [$name, $position, $required, $inputs]) {
        ProjectDevelopmentDeployStep::query()->create(['project_id' => $instance->project_id, 'name' => $name, 'position' => $position, 'command' => $name,
            'required' => $required, 'timeout_seconds' => 300, 'inputs' => $inputs]);
    }
    $commit = str_repeat('c', 40);
    // Operation 31024 on Instance 3847 ran from 08:43:20 to 08:44:13; its run's end was projected a second later.
    $this->travelTo(Carbon::parse('2026-11-20T08:44:14Z'));
    $snapshot = app(InstanceDeploymentIntent::class)->snapshot($instance);
    expect(array_column($snapshot['steps'], 'inputs', 'name'))->toBe(['npm-build' => [], 'composer-install' => ['composer.json', 'composer.lock'], 'tia-warm' => [], 'migrate' => []]);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'input' => ['deployment' => $snapshot], 'state' => ['swamp_run_id' => 'run-1']]);
    $failed = array_keys(array_filter($ended, static fn (array $step): bool => in_array($step['status'], ['failed', 'interrupted'], true)));

    app(InstanceDeployProjection::class)->apply($operation, ['status' => $status, 'run_id' => 'run-1', 'started_at' => '2026-11-20T08:43:20Z',
        'finished_at' => '2026-11-20T08:44:13Z', 'result' => ['steps' => [
            ContainerDeployOutcome::ResultStep => ['stdout' => json_encode(['commit' => $commit], JSON_THROW_ON_ERROR)],
            // The checkout found the inputs of `composer-install` unchanged.
            ContainerDeployOutcome::SkipStep => ['status' => 'succeeded', 'stdout' => ',composer-install,'], ...$ended],
            'summary' => ['failed_steps' => $status === 'failed' ? ($failed === [] ? ['Run dev deploy steps'] : $failed) : []]]]);

    $row = InstanceDeployment::query()->findOrFail($operation->refresh()->state['deployment_id']);
    expect($row->status)->toBe($status)
        ->and($row->started_at?->toIso8601ZuluString())->toBe('2026-11-20T08:43:20Z')
        ->and($row->finished_at?->toIso8601ZuluString())->toBe('2026-11-20T08:44:13Z')
        ->and($row->duration_seconds)->toBe(53)
        ->and(array_column($row->events ?? [], 'status', 'step_name'))->toBe(array_combine(['npm-build', 'composer-install', 'tia-warm', 'migrate'], $events))
        ->and($row->events[0] ?? null)->toBe(['type' => 'phase', 'phase' => 'before_activation', 'step_name' => 'npm-build', 'status' => $events[0]]);
})->with([
    'passed, one step skipped by its inputs, a best-effort step timed out' => ['succeeded', [
        'Run dev deploy steps-0-0' => ['status' => 'succeeded', 'stdout' => ''], 'Run dev deploy steps-1-1' => ['status' => 'skipped'],
        'Run dev deploy steps-2-2' => ['status' => 'succeeded', 'stdout' => "124\n"], 'Run dev deploy steps-3-3' => ['status' => 'succeeded', 'stdout' => '']],
        ['succeeded', 'skipped_unchanged', 'timed_out', 'succeeded']],
    'a best-effort step failed and the deployment passed' => ['succeeded', [
        'Run dev deploy steps-0-0' => ['status' => 'succeeded', 'stdout' => ''], 'Run dev deploy steps-1-1' => ['status' => 'skipped'],
        'Run dev deploy steps-2-2' => ['status' => 'succeeded', 'stdout' => "1\n"], 'Run dev deploy steps-3-3' => ['status' => 'succeeded', 'stdout' => '']],
        ['succeeded', 'skipped_unchanged', 'failed', 'succeeded']],
    'the first step failed, so no later one was reached, the unchanged one neither' => ['failed', [
        'Run dev deploy steps-0-0' => ['status' => 'failed'], 'Run dev deploy steps-1-1' => ['status' => 'skipped'],
        'Run dev deploy steps-2-2' => ['status' => 'skipped'], 'Run dev deploy steps-3-3' => ['status' => 'skipped']],
        ['failed', 'not_run', 'not_run', 'not_run']],
    'the run ended inside a step, before the rest had an entry' => ['failed', [
        'Run dev deploy steps-0-0' => ['status' => 'succeeded', 'stdout' => ''], 'Run dev deploy steps-1-1' => ['status' => 'skipped'],
        'Run dev deploy steps-2-2' => ['status' => 'interrupted']],
        ['succeeded', 'skipped_unchanged', 'interrupted', 'not_run']],
    'the run ended inside the first step, so the unchanged one has no entry' => ['failed', [
        'Run dev deploy steps-0-0' => ['status' => 'running']],
        ['interrupted', 'not_run', 'not_run', 'not_run']],
]);

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
        ->and($runtime->seed_taken_at)->toBeNull()
        ->and($operation->refresh()->error_code)->toBe('deployment.checkout_dirty');
});

it('fails a deployment whose seed hold stayed busy with instance.seed_hold_timeout, before the container started', function (): void {
    $instance = deployDefault(container: true);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)], 'state' => ['swamp_run_id' => 'run-1']]);

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['steps' => [], 'summary' => ['failed_steps' => ['Hold the seed']]]]);

    expect($operation->refresh()->error_code)->toBe('instance.seed_hold_timeout')
        ->and(InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
});

it('asks for no seed hold and adds no wait to the budget for a default whose seed no seed copy sends', function (): void {
    $instance = deployDefault(container: true);

    expect(app(InstanceDeploymentIntent::class)->snapshot($instance)['container'])->not->toHaveKey('seed_hold');
});

it('records the admitted commit on a failed automatic container deployment, so policy counts failures per commit', function (): void {
    $instance = deployDefault(container: true);
    $commit = str_repeat('c', 40);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'triggered_by' => 'swamp:auto-deploy',
        'input' => ['commit' => $commit, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)], 'state' => ['swamp_run_id' => 'run-1']]);

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['steps' => [ContainerDeployOutcome::ResultStep => ['stdout' => '{"commit":null}']],
            'summary' => ['failed_steps' => ['Run dev deploy steps-0']]]]);

    $row = InstanceDeployment::query()->findOrFail($operation->refresh()->state['deployment_id']);
    expect($row->status)->toBe('failed')->and($row->commit)->toBe($commit)
        ->and(InstanceRuntime::query()->where('instance_id', $instance->id)->value('seed_commit'))->toBeNull();
});

it('records a container default\'s step that waited 60 s for a behavior lock as instance.deploy_lock_timeout', function (string $detail, string $file, string $code): void {
    $instance = deployDefault(container: true);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)], 'state' => ['swamp_run_id' => 'run-1']]);

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'failure' => ['failed_step' => 'Finish the container', 'detail' => $detail],
        'result' => ['steps' => [ContainerDeployOutcome::ResultStep => ['stdout' => $file]], 'summary' => ['failed_steps' => ['Finish the container']]]]);

    expect($operation->refresh()->error_code)->toBe($code)
        ->and($operation->failed_step)->toBe('Finish the container');
})->with([
    // Operation 22649: its Finish waited on the Finish of another default's deployment on the same Node.
    'held' => ['Lock "/home/orbit/swamp/.swamp/data/command/shell/271a8c06-0b1e-4c5d-9f00-1a2b3c4d5e6f/.lock" held by orbit@swamp (pid 4242) — timed out after 60001ms',
        '{"commit":null,"phases_passed":["start","fetch","checkout"]}', 'instance.deploy_lock_timeout'],
    'let go at the deadline' => ['Lock "/home/orbit/swamp/.swamp/data/command/shell/271a8c06-0b1e-4c5d-9f00-1a2b3c4d5e6f/.lock" — timed out after 60000ms',
        '{"commit":null}', 'instance.deploy_lock_timeout'],
    // A restore that also failed adds its words to the detail; the native lock line still names the first failure.
    'restore failed too' => ['Lock "/home/orbit/swamp/.swamp/data/command/shell/271a8c06-0b1e-4c5d-9f00-1a2b3c4d5e6f/.lock" — timed out after 60000ms',
        '{"commit":null,"restore_failure":"restore_failure: the restored fleet did not start"}', 'instance.deploy_lock_timeout'],
    'own step timeout' => ['Finish the container timed out after 300s', '{"commit":null}', 'operation.native_failed'],
    'lock line inside other output' => ["ansible failed\nLock \"/x/.swamp/data/a/.lock\" — timed out after 60000ms", '{"error_code":"instance.container_processes_failed"}', 'instance.container_processes_failed'],
]);

it('keeps the step code of a checkout default whose step waited for a behavior lock', function (): void {
    $instance = deployDefault(container: false);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)], 'state' => ['swamp_run_id' => 'run-1']]);

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'failure' => ['failed_step' => 'Fetch the branch',
            'detail' => 'Lock "/home/orbit/swamp/.swamp/data/@swamp/ssh/271a8c06-0b1e-4c5d-9f00-1a2b3c4d5e6f/.lock" — timed out after 60000ms'],
        'result' => ['steps' => [], 'summary' => ['failed_steps' => ['Fetch the branch']]]]);

    expect($operation->refresh()->error_code)->toBe('deployment.source_failed');
});

it('records the seed commit and a running container after a deployment passes, and leaves the checkout seed alone', function (): void {
    $instance = deployDefault(container: true);
    $commit = str_repeat('c', 40);
    $instance->update(['auto_deploy_state' => ['result' => 'started', 'head' => $commit, 'commit' => $commit]]);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'triggered_by' => 'swamp:auto-deploy', 'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)], 'state' => ['swamp_run_id' => 'run-1']]);

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => [
        'steps' => ['Read the container result' => ['stdout' => json_encode(['commit' => $commit], JSON_THROW_ON_ERROR)]], 'summary' => ['failed_steps' => []]]]);

    $runtime = InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail();
    expect($runtime->seed_commit)->toBe($commit)
        // A retake at the same commit keeps the seed's name, so the time names the seed too (docs/reference/development-containers.md#records).
        ->and($runtime->seed_taken_at)->not->toBeNull()
        ->and($runtime->power_state)->toBe(InstanceRuntimePowerState::Running)
        ->and($instance->refresh()->seed_commit)->toBeNull()
        ->and($instance->auto_deploy_state['result'])->toBe('up_to_date')
        ->and($instance->deployments()->latest('id')->firstOrFail()->commit)->toBe($commit);
});

it('checks the executor receipt before accepting a successful deployment wrapper', function (bool $resume, ?array $verdict, ?string $wake, bool $succeeded): void {
    $instance = deployDefault(container: true);
    config(['orbit.executor.power_enabled' => true]);
    $instance->runtime()->update(['power_state' => $resume ? 'stopped' : 'running']);
    if ($resume) {
        Operation::query()->create(['kind' => 'instance.sleep', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'status' => OperationStatus::Succeeded, 'input' => ['signed_power' => true], 'result' => ['sleep' => 'frozen']]);
    }
    $intent = app(InstanceDeploymentIntent::class)->snapshot($instance);
    expect($intent['executor_lifecycle_phase'])->toBe($resume ? 'resume' : 'boot');
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'input' => ['deployment' => $intent], 'state' => ['swamp_run_id' => 'run-1']]);
    $file = ['commit' => str_repeat('c', 40), 'wake' => $wake];
    if ($verdict !== null) {
        $file['executor'] = [...$verdict, 'operation' => (string) $operation->id, 'action' => 'wake', 'target' => (string) $instance->id];
    }
    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['steps' => ['Read the container result' => ['stdout' => json_encode($file, JSON_THROW_ON_ERROR)]]]]);

    expect($operation->refresh()->status)->toBe($succeeded ? OperationStatus::Succeeded : OperationStatus::Failed)
        ->and($instance->runtime()->firstOrFail()->power_state)->toBe($succeeded ? InstanceRuntimePowerState::Running : InstanceRuntimePowerState::Stopped);
})->with([
    'refusal' => [true, ['status' => 'refused', 'reason' => 'unknown_target'], null, false],
    'missing resume receipt' => [true, null, null, false],
    'stopped without boot' => [true, ['status' => 'failed', 'code' => 'instance.container_stopped'], null, false],
    'stopped with verified boot' => [true, ['status' => 'failed', 'code' => 'instance.container_stopped'], 'started', true],
    'no action needed' => [false, null, null, true],
]);

it('fails the deployment record with the Operation\'s code when the run ended without a readable result', function (string $kind): void {
    $instance = deployDefault(container: true);
    $commit = str_repeat('d', 40);
    test()->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead($commit));
    $deploy = app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);
    $run = '7a1e2b3c-4d5e-4f60-8a71-92b3c4d5e6f7';
    // A deploy or rollback that records its running history row at admission, as a production one does.
    $row = app(InstanceDeploymentRecorder::class)->start($instance, 'manual', $kind === 'instance.deploy' ? 'deploy' : 'rollback');
    $operation = $kind === 'instance.deploy' ? $deploy : Operation::query()->create(['kind' => 'instance.rollback', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id, 'input' => ['release' => 'r1', 'commit' => $commit], 'status' => OperationStatus::Running]);
    $operation->update(['status' => OperationStatus::Running, 'state' => [...($operation->state ?? []), 'deployment_id' => $row->id, 'swamp_run_id' => $run]]);

    app(FailUnreadableResultAction::class)->execute($operation, $run, ['status' => 'succeeded', 'completedAt' => now()->toIso8601ZuluString()],
        'Check the checkout', 'skipped');

    $deployment = $row->refresh();
    expect($operation->refresh()->error_code)->toBe('operation.result_unreadable')
        ->and($deployment->status)->toBe('failed')
        ->and($deployment->error_code)->toBe('operation.result_unreadable');
})->with(['instance.deploy', 'instance.rollback']);

/** A container default with the development Processes worker and mailer, both installed and running. */
function deployDefaultWithProcesses(): Instance
{
    $instance = deployDefault(container: true);
    foreach (['mailer', 'worker'] as $name) {
        ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $name, 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', "{$name}.js"], 'restart_policy' => 'always']]);
    }
    app(InstantiateProjectRuntimeDefinitionsAction::class)->refreshForContainer($instance);
    $instance->processes()->update(['status' => LifecycleStatus::Active]);

    return $instance;
}

/** Admits a real deployment of the default and projects its run's end. */
function deployAndFinish(Instance $instance, string $status, string $commit): Operation
{
    test()->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead($commit));
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => "run-{$commit}"]]);
    app(InstanceDeployProjection::class)->apply($operation, ['status' => $status, 'run_id' => "run-{$commit}", 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['steps' => $status === 'succeeded'
            ? ['Read the container result' => ['stdout' => json_encode(['commit' => $commit], JSON_THROW_ON_ERROR)]] : [],
            'summary' => ['failed_steps' => $status === 'succeeded' ? [] : ['Finish the container']]]]);

    return $operation->refresh();
}

/** @return list<string> */
function deployedUnitNames(Operation $operation): array
{
    return array_column(ValidatedData::object(ValidatedData::object($operation->input['deployment'])['container'])['processes'], 'name');
}

it('keeps every Process of a default whose definitions did not change, and names none removed', function (): void {
    Queue::fake();
    $instance = deployDefaultWithProcesses();
    $before = $instance->processes()->orderBy('name')->get(['id', 'name', 'source_definition_id', 'status'])->toArray();

    $operation = deployAndFinish($instance, 'succeeded', str_repeat('c', 40));

    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result['processes_removed'])->toBe([])
        ->and(deployedUnitNames($operation))->toHaveCount(2)
        ->and($instance->processes()->orderBy('name')->get(['id', 'name', 'source_definition_id', 'status'])->toArray())->toBe($before);
});

it('removes a Process whose definition the Project removed at the next passed deployment, and names it', function (): void {
    Queue::fake();
    $instance = deployDefaultWithProcesses();
    $mailer = $instance->processes()->where('name', 'mailer')->sole();
    $worker = $instance->processes()->where('name', 'worker')->sole();
    ProcessDefinition::query()->where('name', 'mailer')->delete();

    // A failed run keeps the record, marked removing and out of the run's units: the processes phase removes the unit as another owner's.
    $failed = deployAndFinish($instance, 'failed', str_repeat('d', 40));
    expect(deployedUnitNames($failed))->toBe(["orbit-process-{$worker->id}-worker.service"])
        ->and($mailer->refresh()->status)->toBe(LifecycleStatus::Removing)
        ->and($failed->result)->not->toHaveKey('processes_removed')
        ->and(processAdmissionError(fn () => app(ProcessOperationAdmission::class)->execute($mailer, 'start')))->toBe('process.removing')
        ->and(processAdmissionError(fn () => app(ProcessOperationAdmission::class)->execute($mailer, 'restart')))->toBe('process.removing')
        ->and(processAdmissionError(fn () => app(ProcessOperationAdmission::class)->execute($mailer, 'stop')))->toBe('process.removing')
        ->and($mailer->refresh()->status)->toBe(LifecycleStatus::Removing);

    $passed = deployAndFinish($instance, 'succeeded', str_repeat('e', 40));
    expect($passed->result['processes_removed'])->toBe(['mailer'])
        ->and(Process::query()->whereKey($mailer->id)->exists())->toBeFalse()
        ->and($worker->refresh()->status)->toBe(LifecycleStatus::Active);

    // The same terminal result again, such as a retried result post, replays the stored one.
    $replayed = app(InstanceDeployProjection::class)->apply($passed, ['status' => 'succeeded', 'run_id' => 'run-'.str_repeat('e', 40),
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['steps' => [
            'Read the container result' => ['stdout' => json_encode(['commit' => str_repeat('e', 40)], JSON_THROW_ON_ERROR)]], 'summary' => ['failed_steps' => []]]]);
    expect($replayed->result)->toBe($passed->result);
});

it('installs a removing Process again when its definition applies to development again', function (): void {
    Queue::fake();
    $instance = deployDefaultWithProcesses();
    $mailer = $instance->processes()->where('name', 'mailer')->sole();
    $definition = ProcessDefinition::query()->where('name', 'mailer')->sole();
    $definition->update(['environments' => ['production']]);
    deployAndFinish($instance, 'failed', str_repeat('d', 40));
    expect($mailer->refresh()->status)->toBe(LifecycleStatus::Removing);
    $definition->update(['environments' => ['development']]);

    $operation = deployAndFinish($instance, 'succeeded', str_repeat('e', 40));

    expect($operation->result['processes_removed'])->toBe([])
        ->and($mailer->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and(deployedUnitNames($operation))->toContain("orbit-process-{$mailer->id}-mailer.service");
});

it('replaces a removed definition\'s Process when the Project adds a definition of the same name again', function (): void {
    Queue::fake();
    $instance = deployDefaultWithProcesses();
    $old = $instance->processes()->where('name', 'mailer')->sole();
    $old->update(['desired_state' => 'stopped']);
    ProcessDefinition::query()->where('name', 'mailer')->delete();
    deployAndFinish($instance, 'failed', str_repeat('d', 40));
    $definition = ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'mailer', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', 'mailer.js', '--v2'], 'restart_policy' => 'always']]);

    $operation = deployAndFinish($instance, 'succeeded', str_repeat('e', 40));

    $new = $instance->processes()->where('name', 'mailer')->sole();
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and(Process::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and($new->source_definition_id)->toBe($definition->id)
        ->and($new->desired_state->value)->toBe('running')
        ->and($new->status)->toBe(LifecycleStatus::Active)
        ->and(deployedUnitNames($operation))->toContain("orbit-process-{$new->id}-mailer.service")
        ->and($operation->result['processes_removed'])->toBe([]);
});

it('leaves a Process recorded without a definition', function (): void {
    Queue::fake();
    $instance = deployDefaultWithProcesses();
    $instance->processes()->where('name', 'mailer')->update(['source_definition_id' => null]);
    ProcessDefinition::query()->where('name', 'mailer')->delete();

    $operation = deployAndFinish($instance, 'succeeded', str_repeat('c', 40));

    expect($operation->result['processes_removed'])->toBe([])
        ->and($instance->processes()->where('name', 'mailer')->sole()->status)->toBe(LifecycleStatus::Active);
});

function processAdmissionError(Closure $call): string
{
    try {
        $call();
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }

    return 'admitted';
}

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

it('refuses an automatic deployment of a checkout default before any work', function (): void {
    Queue::fake();
    $instance = deployDefault(container: false);
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    $this->mock(ProjectSourceReader::class)->shouldNotReceive('read');

    expect(fn () => app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, str_repeat('a', 40)))
        ->toThrow(ResourceOperationException::class, 'Automatic deployment requires eligible active app-dev or app-prod placement.');
    expect(Operation::query()->where('kind', 'instance.deploy')->exists())->toBeFalse();
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
    $instance->forceFill(['starting_commit' => $commit])->save();
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'adopt.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $instance->routes()->attach($route->id, ['position' => 0]);
    $route->update(['status' => 'active']);

    $operation = $automatic
        ? app(StartInstanceDeployAction::class)->automatic($instance, $instance->node, null, $commit)
        : app(StartInstanceDeployAction::class)->execute($instance, 'manual', null, $commit);

    expect($operation->input['deployment']['variant'])->toBe('production-release');
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
        'runtime_version' => 'r', 'agent_version' => 'a', 't3_port' => 3800, 'container_server' => 't3', 'clone_source_instance_id' => $instance->id,
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
            'layout' => ['gateway' => ['address' => '10.232.2.11', 'wireguard' => '10.45.0.1', 'memory' => '4GiB'],
                'app-dev' => ['address' => '10.232.2.12', 'wireguard' => '10.45.0.2', 'memory' => '5GiB'],
                'app-prod' => ['address' => '10.232.2.13', 'wireguard' => '10.45.0.3', 'memory' => '2GiB']],
            'host' => true,
            'operator' => ['address' => '10.45.0.10', 'hub' => '10.45.0.1', 'network' => '10.45.0.0/24', 'domains' => ['nested-dev', 'nested-prod']],
            'checkout' => ['path' => '/home/orbit/orbit'],
            'proxy' => ['HTTP_PROXY' => 'http://10.253.0.1:3128', 'HTTPS_PROXY' => 'http://10.253.0.1:3128', 'http_proxy' => 'http://10.253.0.1:3128',
                'https_proxy' => 'http://10.253.0.1:3128', 'NO_PROXY' => 'localhost,127.0.0.1,::1,.orbit,10.45.0.1,10.45.0.0/24,10.232.2.0/24',
                'no_proxy' => 'localhost,127.0.0.1,::1,.orbit,10.45.0.1,10.45.0.0/24,10.232.2.0/24'],
        ]);
        // A full-size default leaves room for the Instance host; below 2,000,000 the refresh builds none
        // (docs/reference/development-containers.md#instance-host-in-the-fleet).
        foreach ([1_165_536 => false, 2_000_000 => true, 101_000_000 => true] as $size => $host) {
            $instance->runtime()->update(['idmap_size' => $size]);
            expect(app(InstanceDeploymentIntent::class)->snapshot($instance->refresh())['fleet']['host'])->toBe($host);
        }
    }
})->with([
    'nested-fleet default' => [true, true, InstancePlacement::NestedProject, true],
    'switch off' => [true, false, InstancePlacement::NestedProject, false],
    'flag off' => [false, true, InstancePlacement::NestedProject, false],
    'normal project runtime' => [true, true, 'orbit-instances', false],
    'normal Project' => [false, false, 'orbit-instances', false],
]);

it('gives only a clone of a nested-fleet default on a switched-on Node the fleet of its create', function (bool $flag, bool $switch, string $incusProject, bool $fleet): void {
    $default = nestedFleetDefault($flag, $switch, $incusProject);
    $clone = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $default->node_id, 'name' => 'seedcopy1',
        'checkout_path' => '/srv/tool/seedcopy1', 'status' => InstanceState::Reserved]);
    $runtime = InstanceRuntime::query()->create(['instance_id' => $clone->id, 'node_id' => $default->node_id, 'incus_project' => $incusProject,
        'container_name' => 'instance-'.$clone->id, 'bridge_address' => '10.253.0.3', 't3_port' => 3800, 'container_server' => 't3', 'clone_source_instance_id' => $default->id,
        'runtime_version' => 'r', 'agent_version' => 'a', 'power_state' => InstanceRuntimePowerState::Stopped,
        // A copy takes its default's ID map size at its reservation (InstanceContainer).
        'idmap_size' => $default->runtime()->value('idmap_size')]);
    $intents = app(NestedFleetIntent::class);

    // The clone's create gets the same fleet as its default's deployment; a deployment never treats a clone as a default.
    expect($intents->forClone($clone, $runtime))->toBe($fleet ? app(InstanceDeploymentIntent::class)->snapshot($default)['fleet'] : null)
        ->and($intents->forDeployment($clone, $runtime))->toBeNull()
        // The default itself has no T3 port: its create and deployments never take the clone's fleet start.
        ->and($intents->forClone($default, $default->runtime()->firstOrFail()))->toBeNull();
})->with([
    'clone of the nested-fleet default' => [true, true, InstancePlacement::NestedProject, true],
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
        't3_port' => 3800, 'container_server' => 't3', 'clone_source_instance_id' => $instance->id, 'power_state' => InstanceRuntimePowerState::Stopped, 'idmap_size' => 101000000]);
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

    [$data, $payload] = $run('{"error_code":"instance.container_fleet_refresh_failed","failed_phase":"fleet_refresh"}', ['Finish the container']);
    expect($data['error_code'])->toBe('instance.container_fleet_refresh_failed')
        ->and($payload['failed_step'])->toBe('Finish the container')
        ->and($payload['started'])->toBeTrue();
    [$data, $payload] = $run('{"error_code":"instance.container_fleet_start_failed","failed_phase":"fleet_start","phases_passed":["start"]}', ['Prepare the container']);
    expect($data['error_code'])->toBe('instance.container_fleet_start_failed')->and($payload['failed_step'])->toBe('Prepare the container')
        ->and($payload['started'])->toBeTrue()
        ->and($payload)->not->toHaveKey('fleet_drift');
    // A refused check names each difference; nothing changed, so no restore ran.
    [$data, $payload] = $run('{"error_code":"instance.container_fleet_drift","fleet_drift":["nested-gateway config raw.idmap"],"failed_phase":"fleet_start"}', ['Prepare the container']);
    expect($data['error_code'])->toBe('instance.container_fleet_drift')
        ->and($payload['failed_step'])->toBe('Prepare the container')
        ->and($payload['fleet_drift'])->toBe(['nested-gateway config raw.idmap']);
});

it('records why a fleet phase failed, adds a restored fleet that did not start, and leaves other failures to the native step', function (): void {
    $run = static fn (array $file, array $failed): array => ContainerDeployOutcome::from(['status' => 'failed',
        'failure' => ['failed_step' => $failed[0], 'detail' => 'Command exited with code 2', 'stderr_recorded' => false],
        'result' => ['steps' => ['Read the container result' => ['stdout' => json_encode($file, JSON_THROW_ON_ERROR)]], 'summary' => ['failed_steps' => $failed]]]);
    $doctor = "Check Doctor: Readiness check 'doctor' failed after 300 s: {\"data\":{\"healthy\":false}}";

    [$data] = $run(['error_code' => 'instance.container_fleet_start_failed', 'detail' => $doctor, 'failed_phase' => 'fleet_start'], ['Prepare the container']);
    expect($data['failure'])->toBe(['failed_step' => 'Prepare the container', 'detail' => $doctor, 'stderr_recorded' => true]);
    // The restored fleet did not start either: the first failure stays, and the detail says so.
    [$data, $payload] = $run(['error_code' => 'instance.container_fleet_refresh_failed', 'detail' => 'Migrate the nested Gateway: non-zero return code',
        'restore_failure' => 'The inner fleet of the restored seed did not start: Check the agents: x'], ['Finish the container', 'Start the restored fleet']);
    expect($data['error_code'])->toBe('instance.container_fleet_refresh_failed')->and($payload['failed_step'])->toBe('Finish the container')
        ->and($data['failure']['detail'])->toBe('Migrate the nested Gateway: non-zero return code The inner fleet of the restored seed did not start: Check the agents: x');
    [$data] = $run(['error_code' => 'deployment.checkout_dirty', 'restore_failure' => 'The inner fleet of the restored seed did not start: y'], ['Check out in the container']);
    expect($data['failure']['detail'])->toBe('Command exited with code 2 The inner fleet of the restored seed did not start: y');
    // A credential-shaped value in a message is redacted.
    [$data] = $run(['error_code' => 'instance.container_fleet_refresh_failed', 'detail' => 'Migrate: password=hunter2-secret-value'], ['Finish the container']);
    expect($data['failure']['detail'])->not->toContain('hunter2-secret-value');
    // A normal container phase writes no detail: the native step's failure stays as it was.
    [$data, $payload] = $run(['error_code' => 'deployment.checkout_dirty'], ['Check out in the container']);
    expect($data['failure'])->toBe(['failed_step' => 'Check out in the container', 'detail' => 'Command exited with code 2', 'stderr_recorded' => false]);
});

it('names a failed seed in the Operation and leaves an Activity problem when the release refresh fails', function (string $trigger, bool $problem): void {
    $instance = deployDefault(container: true);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'triggered_by' => $trigger, 'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)],
        'state' => ['swamp_run_id' => 'run-1']]);
    // As refresh 21308 on 2026-11-14: Incus found no free ID range for the snapshot; the seed step kept the old seed.
    $detail = "No free ID range for the new seed: every isolated range of root's subordinate IDs is held by a container. The old seed stays.";

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'failure' => ['failed_step' => 'Prepare the container', 'detail' => 'Command exited with code 2', 'stderr_recorded' => false], 'result' => [
            'steps' => ['Read the container result' => ['stdout' => json_encode(['error_code' => 'instance.container_seed_failed', 'detail' => $detail, 'failed_phase' => 'seed'], JSON_THROW_ON_ERROR)]],
            'summary' => ['failed_steps' => ['Prepare the container']]]]);

    $operation->refresh();
    expect($operation->error_code)->toBe('instance.container_seed_failed')
        ->and($operation->state['native_failure'])->toBe(['failed_step' => 'Prepare the container', 'detail' => $detail, 'stderr_recorded' => true]);
    $rows = Activity::query()->where('description', 'instance.refresh_failed')->get();
    expect($rows->count())->toBe($problem ? 1 : 0);
    if ($problem) {
        $row = $rows->sole();
        expect([$row->status, $row->error_code, $row->command, $row->subject_id])->toBe(['failed', 'instance.refresh_failed', 'instances:refresh-nested-fleets', $instance->id])
            ->and($row->properties?->get('operation_id'))->toBe($operation->id)
            ->and($row->properties?->get('operation_error_code'))->toBe('instance.container_seed_failed')
            ->and($row->properties?->get('error_message'))->toContain('No free ID range for the new seed');
    }
})->with([
    'the refresh a release admitted' => [NestedFleetRefresh::Trigger, true],
    'a deployment by hand' => ['manual', false],
]);

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

it('resumes a pinned default refresh from the Operation and records the fresh seed without deploying twice', function (): void {
    Queue::fake();
    config(['instances.nested_fleet_refresh' => true]);
    $commit = str_repeat('a', 40);
    $instance = nestedFleetDefault();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')
        ->with($instance->project->repository_url, ProjectSourceAccess::GitHubApp, 'main', ['.env.example', 'composer.json', 'artisan'], $commit)
        ->once()->andReturn(deploySourceRead($commit));

    $first = app(NestedFleetRefresh::class)->advance($commit);
    $operation = Operation::query()->where('kind', 'instance.deploy')->sole();
    expect($first)->toBe(['status' => 'pending', 'defaults' => [$instance->id => ['status' => 'pending', 'operation_id' => $operation->id]]])
        ->and(app(NestedFleetRefresh::class)->advance($commit))->toBe($first);

    $operation->update(['status' => 'succeeded', 'lock_key' => null, 'finished_at' => now()]);
    $instance->runtime()->update(['seed_commit' => $commit, 'seed_taken_at' => now()]);
    $current = app(NestedFleetRefresh::class)->advance($commit);
    expect($current['status'])->toBe('current')
        ->and($current['defaults'][$instance->id])->toMatchArray(['status' => 'current', 'operation_id' => $operation->id, 'commit' => $commit])
        ->and(Operation::query()->where('kind', 'instance.deploy')->count())->toBe(1);
});

it('backs off after a failed refresh and ends after the third attempt', function (): void {
    Queue::fake();
    config(['instances.nested_fleet_refresh' => true]);
    $commit = str_repeat('a', 40);
    $instance = nestedFleetDefault();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')
        ->with($instance->project->repository_url, ProjectSourceAccess::GitHubApp, 'main', ['.env.example', 'composer.json', 'artisan'], $commit)
        ->times(3)->andReturn(deploySourceRead($commit));
    $refresh = app(NestedFleetRefresh::class);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $receipt = $refresh->advance($commit)['defaults'][$instance->id];
        expect($receipt['status'])->toBe('pending');
        $operation = Operation::query()->findOrFail($receipt['operation_id']);
        $operation->update(['status' => 'failed', 'lock_key' => null, 'finished_at' => now(), 'error_code' => 'instance.container_fleet_refresh_failed']);
        expect($refresh->advance($commit)['defaults'][$instance->id])->toBe([
            'status' => $attempt === 3 ? 'failed' : 'pending',
            'error_code' => 'instance.container_fleet_refresh_failed', 'operation_id' => $operation->id,
        ])->and(Operation::query()->where('kind', 'instance.deploy')->count())->toBe($attempt);
        $this->travel(10)->minutes();
    }
});

it('records a failed source read before admission and resumes it after backoff', function (): void {
    Queue::fake();
    config(['instances.nested_fleet_refresh' => true]);
    $commit = str_repeat('a', 40);
    $instance = nestedFleetDefault();
    $source = $this->mock(ProjectSourceReader::class);
    $source->shouldReceive('read')->once()->ordered()
        ->andThrow(new ResourceOperationException('project.source_unavailable', 'Temporary source failure.', 502));
    $source->shouldReceive('read')->twice()->ordered()->andReturn(deploySourceRead($commit));

    $recorded = [];
    $first = app(NestedFleetRefresh::class)->advance($commit, recordDefault: static function (int $id, array $row) use (&$recorded): void {
        $recorded[$id] = $row;
    });
    expect($recorded)->toBe($first['defaults']);
    expect($first['defaults'][$instance->id])->toMatchArray([
        'status' => 'pending', 'error_code' => 'project.source_unavailable', 'admission_failures' => 1,
    ])->and(app(NestedFleetRefresh::class)->advance($commit, $first))->toBe($first)
        ->and(Operation::query()->where('kind', 'instance.deploy')->count())->toBe(0);
    $next = $first;
    for ($attempt = 2; $attempt <= 3; $attempt++) {
        $this->travel(10)->minutes();
        $next = app(NestedFleetRefresh::class)->advance($commit, $next);
        $row = $next['defaults'][$instance->id];
        $operation = Operation::query()->findOrFail($row['operation_id']);
        expect($row['status'])->toBe('pending')->and($row['admission_failures'])->toBe(1);
        $operation->update(['status' => 'failed', 'lock_key' => null, 'finished_at' => now(), 'error_code' => 'instance.container_fleet_refresh_failed']);
        $next = app(NestedFleetRefresh::class)->advance($commit, $next);
    }
    expect($next['defaults'][$instance->id]['status'])->toBe('failed')
        ->and(Operation::query()->where('kind', 'instance.deploy')->count())->toBe(2);
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

it('gives a default\'s deployment the files every clone of it needs, and none that is a copy\'s own', function (): void {
    $instance = deployDefault(container: true);
    $files = app(InstanceDeploymentIntent::class)->snapshot($instance)['container']['seed_files'];
    $paths = array_map(static fn (array $item): string => array_values($item)[0]['path'], $files);
    $kinds = array_count_values(array_map(static fn (array $item): string => array_key_first($item), $files));

    expect($paths)->toContain('/usr/local/libexec/orbit-app-user', '/home/agent/.codex/config.toml', InstanceContainer::T3BootDropIn, InstanceContainer::ServerDropIn,
        '/home/agent/.config/orbit/agent', '/home/agent/.claude/CLAUDE.md')
        ->and($paths)->not->toContain('/etc/machine-info', InstanceContainer::ModelEnvironment, InstanceContainer::InstanceFile)
        ->and($kinds)->toMatchArray(['trust' => 1, 'nss' => 1, 'folder' => 1, 'link' => 4])
        // A task copy runs the Conn Server: the seed holds its drop-in.
        ->and(collect($files)->firstWhere('file.path', InstanceContainer::ServerDropIn)['file']['content'])->toContain('conn-server serve');
});

it('reads what a deployment\'s checkout removed from the default\'s tree, names only', function (): void {
    $run = static fn (array $cleaned): array => ContainerDeployOutcome::from(['status' => 'succeeded', 'result' => [
        'steps' => ['Read the container result' => ['stdout' => json_encode(['commit' => str_repeat('b', 40), 'workspace_cleaned' => $cleaned])]],
        'summary' => ['failed_steps' => []]]])[1];

    expect($run(['removed_count' => 2, 'removed' => ['leftover.txt', 'stale/']])['workspace_cleaned'])->toBe(['removed_count' => 2, 'removed' => ['leftover.txt', 'stale/']])
        ->and($run(['removed_count' => 0, 'removed' => [], 'left_count' => 1])['workspace_cleaned'])->toBe(['removed_count' => 0, 'removed' => [], 'left_count' => 1])
        ->and($run(['removed_count' => 'x', 'removed' => []]))->not->toHaveKey('workspace_cleaned')
        ->and($run(['removed_count' => 1, 'removed' => [['nested']]]))->not->toHaveKey('workspace_cleaned');
});

it('lets only a deployment the release or the automatic policy admitted keep a checkout already past its commit, by its admission', function (string $admission, ?string $origin): void {
    Queue::fake();
    deployVault();
    $instance = deployDefault(container: true);
    $commit = str_repeat('a', 40);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead($commit));
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);

    $deploy = app(StartInstanceDeployAction::class);
    $operation = match ($admission) {
        'the release refresh' => $deploy->execute($instance, NestedFleetRefresh::Trigger, null, $commit, release: true),
        'the automatic policy' => $deploy->automatic($instance, $instance->node, null, $commit),
        // The manual endpoint names its calling Node, here one named `release`: a name is no origin.
        'a Node named release by hand' => $deploy->execute($instance, NestedFleetRefresh::Trigger, null, $commit),
    };

    expect($operation->input['origin'] ?? null)->toBe($origin)
        ->and(ContainerDeployOutcome::keepsNewer($operation))->toBe($origin !== null);
})->with([
    'the release refresh' => ['the release refresh', ContainerDeployOutcome::ReleaseOrigin],
    'the automatic policy' => ['the automatic policy', ContainerDeployOutcome::AutomaticOrigin],
    'a Node named release by hand' => ['a Node named release by hand', null],
]);

it('ends a release deployment whose checkout is already past the commit as a no-op that keeps the seed and the Processes', function (): void {
    Queue::fake();
    config(['instances.nested_fleet_refresh' => true]);
    $instance = nestedFleetDefault();
    $release = str_repeat('a', 40);
    $newer = str_repeat('b', 40);
    $taken = now()->subHour()->startOfSecond();
    $instance->runtime()->update(['seed_commit' => $newer, 'seed_taken_at' => $taken, 'power_state' => InstanceRuntimePowerState::Running]);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'triggered_by' => NestedFleetRefresh::Trigger, 'lock_key' => 'instance:'.$instance->id,
        'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance), 'commit' => $release, 'origin' => ContainerDeployOutcome::ReleaseOrigin],
        'state' => ['swamp_run_id' => 'run-1']]);

    // As Operation 31729: a deployment by hand moved the checkout to a newer commit before the release's refresh ran. The checkout
    // phase keeps it and every later phase skips.
    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Read the container result' => ['stdout' => json_encode(['commit' => $newer, 'already_newer' => true, 'phases_passed' => ['checkout']], JSON_THROW_ON_ERROR)],
            'Finish the container' => ['status' => 'skipped']], 'summary' => ['failed_steps' => []]]]);

    $operation->refresh();
    $runtime = $instance->runtime()->firstOrFail();
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->error_code)->toBeNull()
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->result)->toMatchArray(['commit' => $newer, 'already_newer' => true])
        ->and($runtime->seed_commit)->toBe($newer)
        ->and($runtime->seed_taken_at?->toIso8601String())->toBe($taken->toIso8601String())
        ->and(app(NestedFleetRefresh::class)->advance($release)['defaults'][$instance->id])
        ->toMatchArray(['status' => 'current', 'already_newer' => true, 'commit' => $newer, 'operation_id' => $operation->id]);

    // A seed of another commit than the one the no-op named has moved: the refresh publishes nothing.
    $instance->runtime()->update(['seed_commit' => str_repeat('c', 40)]);
    expect(app(NestedFleetRefresh::class)->advance($release)['defaults'][$instance->id])
        ->toMatchArray(['status' => 'failed', 'error_code' => 'instance.release_seed_moved']);
});

/** A GitHub whose compare answers `$compare` for every pair, or throws when it is null; each compare is listed in `$compares`. */
function deployCompare(?GitHubComparisonStatus $compare, array &$compares = []): void
{
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'read', 'read', 'nckrtl', 'organization', 'https://github.com/apps/read', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($compare, &$compares): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReadToken')->andReturn('contents-read');
        $github->shouldReceive('compareCommits')->andReturnUsing(function (string $token, GitHubRepository $repository, string $base, string $head) use ($compare, &$compares): GitHubCommitComparison {
            $compares[] = $base.'...'.$head;

            return $compare === null ? throw GitHubApiException::unavailable() : new GitHubCommitComparison($compare, $base, $base);
        });
    }));
}

it('ends a release deployment of a commit behind the deployed one at admission, with no write and no run', function (): void {
    Queue::fake();
    config(['instances.nested_fleet_refresh' => true]);
    $instance = nestedFleetDefault();
    [$release, $newer] = [str_repeat('5', 40), str_repeat('2', 40)];
    $taken = now()->subHour()->startOfSecond();
    $instance->runtime()->update(['seed_commit' => $newer, 'seed_taken_at' => $taken]);
    $compares = [];
    deployCompare(GitHubComparisonStatus::Ahead, $compares);
    $this->mock(ProjectSourceReader::class)->shouldNotReceive('read');
    $processes = $instance->processes()->get()->toArray();
    $project = $instance->project()->firstOrFail()->toArray();

    // The release's refresh admits the default's deployment, which ends at once; its next pass publishes the kept seed.
    $admitted = app(NestedFleetRefresh::class)->advance($release)['defaults'][$instance->id];
    $operation = Operation::query()->findOrFail($admitted['operation_id']);

    $runtime = $instance->runtime()->firstOrFail();
    expect($compares)->toBe([$release.'...'.$newer])
        ->and($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->state['swamp_run_id'] ?? null)->toBeNull()
        ->and($operation->input['origin'])->toBe(ContainerDeployOutcome::ReleaseOrigin)
        ->and($operation->result)->toMatchArray(['outcome' => 'already_newer', 'kept_commit' => $newer, 'target_commit' => $release, 'commit' => $newer])
        ->and($runtime->seed_commit)->toBe($newer)
        ->and($runtime->seed_taken_at?->toIso8601String())->toBe($taken->toIso8601String())
        ->and($instance->processes()->get()->toArray())->toBe($processes)
        ->and($instance->project()->firstOrFail()->toArray())->toBe($project)
        ->and(app(NestedFleetRefresh::class)->advance($release)['defaults'][$instance->id])
        ->toMatchArray(['status' => 'current', 'already_newer' => true, 'commit' => $newer, 'operation_id' => $operation->id]);
    Queue::assertNothingPushed();
});

it('admits the full deployment when the compare fails, finds no older target, or the deployment is by hand', function (string $case): void {
    Queue::fake();
    deployVault();
    $instance = deployDefault(container: true);
    $commit = str_repeat('5', 40);
    $instance->runtime()->update(['seed_commit' => str_repeat('2', 40), 'seed_taken_at' => now()]);
    $compares = [];
    deployCompare(match ($case) {
        'compare fails' => null,
        'target not behind' => GitHubComparisonStatus::Diverged,
        'by hand' => GitHubComparisonStatus::Ahead,
    }, $compares);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(deploySourceRead($commit));

    $operation = app(StartInstanceDeployAction::class)->pinned($instance, NestedFleetRefresh::Trigger, null, $commit, release: $case !== 'by hand');

    expect($compares)->toHaveCount($case === 'by hand' ? 0 : 1)
        ->and($operation->status->terminal())->toBeFalse()
        ->and($operation->lock_key)->toBe('instance:'.$instance->id)
        ->and($operation->result)->toBeNull();
})->with(['compare fails', 'target not behind', 'by hand']);

it('ends a failed checkout with its own code and the receipt of its restart, never executor.result_unknown, and frees the Instance', function (?string $trigger, string $code): void {
    $instance = deployDefault(container: true);
    config(['orbit.executor.power_enabled' => true]);
    $instance->runtime()->update(['power_state' => 'running']);
    $intent = app(InstanceDeploymentIntent::class)->snapshot($instance);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'triggered_by' => $trigger, 'lock_key' => 'instance:'.$instance->id,
        'input' => ['deployment' => $intent, ...($trigger === null ? [] : ['origin' => ContainerDeployOutcome::ReleaseOrigin])],
        'state' => ['swamp_run_id' => 'run-1', 'executor_order_issued' => true]]);
    // The restart after the restore of the old seed signed a discard order before its boot; "Start on the old seed" adds its receipt
    // to the failed checkout's result.
    $file = ['error_code' => $code, 'executor' => ['status' => 'done', 'operation' => (string) $operation->id,
        'action' => 'discard', 'target' => (string) $instance->id, 'delivery' => 'swamp']];

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'failure' => ['failed_step' => 'Check out in the container', 'detail' => 'Command exited with code 2', 'stderr_recorded' => false],
        'result' => ['steps' => ['Read the container result' => ['stdout' => json_encode($file, JSON_THROW_ON_ERROR)]],
            'summary' => ['failed_steps' => ['Check out in the container', 'Restore the old seed']]]]);

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe($code)
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->executor_result)->not->toBeNull();
    // The Instance is free: a later deployment, such as the next release's refresh, is admitted, never operation.target_busy.
    expect(app(OperationStore::class)->create('instance.deploy', 'instance', $instance->id, $instance->node_id, [], 'test', null,
        lockKey: 'instance:'.$instance->id)->status)->toBe(OperationStatus::Queued);
})->with([
    'a diverged branch of a release deployment' => [NestedFleetRefresh::Trigger, 'deployment.branch_diverged'],
    'tracked changes of a deployment by hand' => [null, 'deployment.checkout_dirty'],
]);

it('fails a deployment by hand of an older commit in its fetch with deployment.commit_behind_checkout and says why', function (): void {
    $instance = deployDefault(container: true);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'status' => OperationStatus::Running, 'triggered_by' => 'cli', 'lock_key' => 'instance:'.$instance->id,
        'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance)], 'state' => ['swamp_run_id' => 'run-1']]);
    $why = 'The requested commit '.str_repeat('a', 40).' is older than the checkout HEAD '.str_repeat('b', 40).' of main; deploy the newer commit instead.';
    $file = ['error_code' => 'deployment.commit_behind_checkout', 'detail' => $why, 'failed_phase' => 'fetch', 'phases_passed' => ['start']];

    app(InstanceDeployProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'failure' => ['failed_step' => 'Prepare the container', 'detail' => 'Command exited with code 2', 'stderr_recorded' => false],
        'result' => ['steps' => ['Read the container result' => ['stdout' => json_encode($file, JSON_THROW_ON_ERROR)]],
            'summary' => ['failed_steps' => ['Prepare the container']]]]);

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('deployment.commit_behind_checkout')
        ->and($operation->lock_key)->toBeNull()
        ->and(json_encode($operation->toArray(), JSON_THROW_ON_ERROR))->toContain('is older than the checkout HEAD');
});
