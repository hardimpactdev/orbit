<?php

declare(strict_types=1);

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationGatewayCommands;
use App\Domain\Operations\OperationGatewayTransport;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Domain\Operations\OperationTransports;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\AgentCommands\OperationAgentTransport;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;

final readonly class KitAgentWrapper implements OperationTransport, OperationTransportInput
{
    public function __construct(private OperationAgentTransport $agent) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return $this->agent->schema($operation, $run);
    }

    public function wireInput(Operation $operation, array $run, #[SensitiveParameter] array $input): mixed
    {
        return $this->agent->wireInput($operation, $run, $input);
    }

    public function run(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        return $this->agent->run($operation, $run, $input);
    }
}

final class KitInvalidTransport implements OperationTransport, OperationTransportInput
{
    public int $runs = 0;

    public function __construct(public string $mode) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return $this->mode === 'permissive' ? true : ($this->mode === 'empty' ? [] : ['type' => 'object', 'additionalProperties' => false]);
    }

    public function wireInput(Operation $operation, array $run, #[SensitiveParameter] array $input): mixed
    {
        if ($this->mode === 'type-error') {
            throw new TypeError('kit-private-token-value');
        }

        return (object) [];
    }

    public function run(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        $this->runs++;

        return [];
    }
}

final readonly class KitNativeSource implements OperationSecretSource
{
    /** @param list<string> $redactions */
    public function __construct(public mixed $value, public array $redactions = []) {}

    public function name(): string
    {
        return 'project.native';
    }

    public function targetTypes(): array
    {
        return ['project'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->target_id;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        return new OperationSecretValue($this->value, $this->redactions);
    }
}

final class KitSecretSource implements OperationSecretSource
{
    public int $resolves = 0;

    public bool $missingOwner = false;

    public bool $rotates = false;

    public ?string $token = null;

    public ?ResourceOperationException $refusal = null;

    public function name(): string
    {
        return 'project.test';
    }

    public function targetTypes(): array
    {
        return ['project'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $this->missingOwner ? null : $operation->target_id;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $this->resolves++;
        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        return new OperationSecretValue($this->token ?? ('kit-private-token-value'.($this->rotates ? '-'.$this->resolves : '')));
    }
}

final class KitGatewayCommand implements OperationGatewayCommand
{
    public int $runs = 0;

    public bool $loseReply = false;

    public bool $refuse = false;

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['token' => ['type' => 'string'], 'port' => ['type' => 'integer']],
            'required' => ['token', 'port'], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $this->runs++;
        expect($input['token'])->toMatch('/^kit-private-token-value(?:-[0-9]+)?$/')->and($input['port'])->toBe(65431);
        if ($this->loseReply) {
            throw new RuntimeException('transport exposed kit-private-token-value');
        }

        return $this->refuse ? ['error_code' => 'test.refused'] : ['backend' => 'active', 'echo' => $input['token'], 'undeclared' => $input['token']];
    }
}

final class KitExactCommand implements OperationGatewayCommand
{
    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['environment' => ['type' => 'object', 'properties' => [
            'ORBIT_SEED_PATH' => ['type' => 'string'], 'ORBIT_SEED_COMMIT' => ['type' => 'string'], 'PUBLIC_TEXT' => ['type' => 'string']],
            'required' => ['ORBIT_SEED_PATH', 'ORBIT_SEED_COMMIT', 'PUBLIC_TEXT'], 'additionalProperties' => false]],
            'required' => ['environment'], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        expect($input)->toBe(['environment' => ['ORBIT_SEED_PATH' => '', 'ORBIT_SEED_COMMIT' => '', 'PUBLIC_TEXT' => "  exact text\n"]]);

        return ['backend' => 'active'];
    }
}

final class KitFamilyExecution implements OperationCompletion, OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        return ['descriptors' => $operation->input['descriptors'],
            ...(isset($operation->state['family_projection']) ? ['result' => $operation->state['family_projection']] : [])];
    }

    public function input(Operation $operation, array $run): array
    {
        return array_find($this->context($operation)['descriptors'], fn (array $d): bool => $d['key'] === $run['key'])['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        if ($operation->input['guard_refuses'] ?? false) {
            throw new ResourceOperationException('deployment.smoke_hold', 'Fresh policy refused.', 409);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        $operation->state = [...($operation->state ?? []), 'projection_count' => ($operation->state['projection_count'] ?? 0) + 1];
        expect(json_encode($receipt))->not->toContain('kit-private-token-value');

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        $projection = $operation->state['family_projection'] ?? null;
        if ($projection !== null && $outcome->result !== app(OperationExecutionContract::class)->result($operation, $projection)) {
            throw new ResourceOperationException('operation.completion_unproven', 'The terminal projection does not match recorded deployment identity.', 409);
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return ($operation->input['activation_boundary'] ?? false)
            ? ['key' => 'activate', 'transport' => 'gateway', 'name' => 'test.command', 'result' => ['backend' => 'active']] : null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->source = new KitSecretSource;
    $this->command = new KitGatewayCommand;
    app()->instance(KitSecretSource::class, $this->source);
    app()->tag([KitSecretSource::class], OperationSecretSource::class);
    app()->instance(KitGatewayCommand::class, $this->command);
    app()->instance(OperationGatewayCommands::class, new OperationGatewayCommands(['test.command' => KitGatewayCommand::class]));
    $this->spec = ['executor' => 'swamp', 'completion' => KitFamilyExecution::class, 'execution' => KitFamilyExecution::class,
        'runs' => [['transport' => 'gateway', 'name' => 'test.command', 'bind' => ['port' => 'input.configuration.ports.0'], 'secrets' => ['token' => 'project.test']]],
        'completion_contract' => ['step' => 'effect', 'transport' => 'gateway', 'name' => 'test.command', 'result' => ['backend' => 'active']],
        'result_fields' => ['backend', 'echo', 'error_code']];
    $this->register = function (): void {
        app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'test.protected' => $this->spec]));
    };
    ($this->register)();
    $this->run = ['worker' => 'worker', 'key' => 'effect', 'transport' => 'gateway', 'name' => 'test.command',
        'input' => ['token' => ['$secret' => 'project.test', 'id' => 27], 'port' => 65431]];
    $this->operation = Operation::query()->create(['kind' => 'test.protected', 'target_type' => 'project', 'target_id' => 27, 'node_id' => null,
        'status' => OperationStatus::Running, 'worker' => 'worker', 'sequence' => 1, 'state' => ['executor_lease_node_id' => $services->id],
        'input' => ['configuration' => ['ports' => [65431]], 'descriptors' => [[...array_diff_key($this->run, ['worker' => true]), 'step' => 'effect', 'resumable' => true, 'required' => true]]]]);
    $this->executorNodeId = $services->id;
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
});

it('hydrates target-owned references only in memory and stores one redacted Gateway receipt and projection', function (): void {
    $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->assertJsonPath('data.execution_context.descriptors.0.input.token.$secret', 'project.test');
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.echo', '[REDACTED]')->assertJsonMissingPath('data.undeclared');
    $this->postJson($this->path.'/run', $this->run)->assertOk();
    expect($this->command->runs)->toBe(1);
    expect($this->operation->refresh()->state['projection_count'])->toBe(1);
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'finished', 'name' => 'effect', 'status' => 'succeeded',
        'result' => ['backend' => 'active', 'echo' => '[REDACTED]'], 'output' => 'echoed [REDACTED]'])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => ['backend' => 'active', 'echo' => '[REDACTED]']])->assertOk();
    expect(json_encode([$this->operation->refresh()->toArray(), $this->operation->steps()->get()->toArray()]))->not->toContain('kit-private-token-value');
    expect($this->source->resolves)->toBe(1);
});

it('refuses foreign owners, raw protected values and changed nested binding before any resolution', function (string $case): void {
    $run = $this->run;
    if ($case === 'foreign') {
        $run['input']['token']['id'] = 99;
        $input = $this->operation->input;
        $input['descriptors'][0]['input'] = $run['input'];
        $this->operation->update(['input' => $input]);
    } elseif ($case === 'raw') {
        $run['input']['token'] = 'caller-private-token';
    } else {
        $run['input']['port'] = 22;
    }
    $this->postJson($this->path.'/run', $run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->source->resolves)->toBe(0)->and($this->command->runs)->toBe(0);
})->with(['foreign', 'raw', 'binding']);

it('preserves safe preflight codes without secret messages or details', function (): void {
    $this->source->refusal = new ResourceOperationException('env.sync_would_drop_keys', 'kit-private-token-value', 409, details: ['token' => 'kit-private-token-value']);
    $response = $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'env.sync_would_drop_keys');
    expect($response->getContent())->not->toContain('kit-private-token-value');
    expect($this->command->runs)->toBe(0);
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'refused', 'name' => 'effect', 'status' => 'failed', 'error_code' => 'env.sync_would_drop_keys'])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'failed', 'error_code' => 'env.sync_would_drop_keys'])->assertOk();
});

it('does not replay an uncertain nonresumable effect or expose a transport exception', function (): void {
    $this->spec['runs'][0]['resumable'] = false;
    $this->spec['runs'][0]['uncertain_error_code'] = 'deployment.interrupted';
    ($this->register)();
    $this->command->loseReply = true;
    $response = $this->postJson($this->path.'/run', $this->run)->assertStatus(502)->assertJsonPath('error.code', 'operation.execution_failed');
    expect($response->getContent())->not->toContain('kit-private-token-value');
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.error_code', 'deployment.interrupted');
    expect($this->command->runs)->toBe(1);
});

it('continues after cancellation only with an already proven activation receipt', function (bool $proven): void {
    $input = [...$this->operation->input, 'activation_boundary' => true];
    $state = $proven ? ['executor_runs' => ['activate' => ['transport' => 'gateway', 'name' => 'test.command', 'result' => ['backend' => 'active']]]] : [];
    $this->operation->update(['input' => $input, 'state' => ['executor_lease_node_id' => $this->executorNodeId, ...$state], 'cancel_requested_at' => now()]);
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath($proven ? 'data.backend' : 'data.error_code', $proven ? 'active' : 'operation.cancelled');
    expect($this->command->runs)->toBe($proven ? 1 : 0);
})->with([true, false]);

it('checks fresh family guards before hydrating or invoking', function (): void {
    $this->operation->update(['input' => [...$this->operation->input, 'guard_refuses' => true]]);
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'deployment.smoke_hold');
    expect($this->source->resolves)->toBe(0)->and($this->command->runs)->toBe(0);
});

it('requires an admitted script digest before hydration', function (): void {
    expect(fn () => OperationSecretValue::script('secret script', hash('sha256', 'another script')))->toThrow(ResourceOperationException::class);
    expect(OperationSecretValue::script('secret script', hash('sha256', 'secret script'))->value)->toBe('secret script');
    expect(json_encode(new OperationSecretValue('secret script')))->not->toContain('secret script');
});

it('allows retry after a safe nonresumable preflight refusal when no effect started', function (): void {
    $this->spec['runs'][0]['resumable'] = false;
    ($this->register)();
    $this->source->refusal = new ResourceOperationException('env.import_preflight_failed', 'private contents', 409);
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'env.import_preflight_failed');
    $this->source->refusal = null;
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.backend', 'active');
    expect($this->command->runs)->toBe(1);
});

it('requires keyed transport proof for an intermediate descriptor step', function (): void {
    $input = $this->operation->input;
    $input['descriptors'][] = [...$input['descriptors'][0], 'key' => 'prepare', 'step' => 'prepare'];
    $this->operation->update(['input' => $input]);
    $step = ['worker' => 'worker', 'key' => 'prepare-finished', 'name' => 'prepare', 'status' => 'succeeded', 'result' => ['backend' => 'active', 'echo' => '[REDACTED]']];
    $this->postJson($this->path.'/step', $step)->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($this->path.'/run', [...$this->run, 'key' => 'prepare'])->assertOk();
    $this->postJson($this->path.'/step', $step)->assertOk();
});

it('does not re-resolve protected sources during later steps context or failure completion', function (string $outcome): void {
    $this->command->loseReply = $outcome === 'uncertain';
    $this->command->refuse = $outcome === 'failed';
    $this->postJson($this->path.'/run', $this->run)->assertStatus($outcome === 'uncertain' ? 502 : 200);
    expect($this->source->resolves)->toBe(1);
    $this->source->refusal = new ResourceOperationException('github.unavailable', 'must not resolve again', 503);
    $this->source->missingOwner = true;
    $this->postJson('/api/v1/operation-executor/claim', ['worker' => 'worker', 'kind' => 'test.protected'])->assertOk();
    $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk();
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'failed', 'name' => 'effect',
        'status' => 'failed', 'error_code' => 'deployment.failed', 'output' => 'already redacted output'])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'failed', 'error_code' => 'deployment.failed'])->assertOk();
    expect($this->source->resolves)->toBe(1);
    expect(json_encode($this->operation->refresh()->state))->not->toContain('kit-private-token-value');
})->with(['success', 'failed', 'uncertain']);

it('binds explicitly convergent retries to references when each mint returns a new token', function (): void {
    $this->spec['runs'][0]['resumable'] = true;
    ($this->register)();
    $this->source->rotates = true;
    $this->command->loseReply = true;
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $this->command->loseReply = false;
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.echo', '[REDACTED]');
    $resolves = $this->source->resolves;
    $this->postJson($this->path.'/run', $this->run)->assertOk();
    expect($this->source->resolves)->toBe($resolves)->and($this->command->runs)->toBe(2);
    $receipt = $this->operation->refresh()->state['executor_runs']['effect'];
    expect($receipt['input'])->toBe($this->run['input'])
        ->and($receipt['references']['token'])->toBe(['$secret' => 'project.test', 'id' => 27])
        ->and($receipt['fingerprint'])->toBe(hash('sha256', json_encode(array_diff_key($this->run, ['worker' => true]), JSON_THROW_ON_ERROR)));
    expect(json_encode($this->operation->toArray()))->not->toContain('kit-private-token-value');
});

it('refuses an unresolved secret owner before minting or running', function (): void {
    $this->source->missingOwner = true;
    $this->postJson($this->path.'/run', $this->run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->source->resolves)->toBe(0)->and($this->command->runs)->toBe(0)
        ->and($this->operation->refresh()->state)->toBe(['executor_lease_node_id' => $this->executorNodeId]);
});

it('marks the Gateway command and hydrated transport parameters as sensitive', function (): void {
    foreach ([OperationGatewayCommand::class, OperationGatewayTransport::class, KitGatewayCommand::class] as $class) {
        $parameter = new ReflectionParameter([$class, 'run'], 'input');
        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }
});

it('preserves empty strings and whitespace in nested executor inputs before exact binding', function (): void {
    app()->instance(OperationGatewayCommands::class, new OperationGatewayCommands(['test.exact' => KitExactCommand::class]));
    $this->spec['runs'] = [['transport' => 'gateway', 'name' => 'test.exact']];
    ($this->register)();
    $input = ['environment' => ['ORBIT_SEED_PATH' => '', 'ORBIT_SEED_COMMIT' => '', 'PUBLIC_TEXT' => "  exact text\n"]];
    $run = [...$this->run, 'name' => 'test.exact', 'input' => $input];
    $admitted = $this->operation->input;
    $admitted['descriptors'][0]['name'] = 'test.exact';
    $admitted['descriptors'][0]['input'] = $input;
    $this->operation->update(['input' => $admitted]);
    $this->postJson($this->path.'/run', $run)->assertOk()->assertJsonPath('data.backend', 'active');
    expect($this->operation->refresh()->state['executor_runs']['effect']['input'])->toBe($input);
    $run['input']['environment']['ORBIT_SEED_PATH'] = null;
    $this->postJson($this->path.'/run', $run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('records and proves dotted descriptor step names', function (string $name): void {
    $this->spec['completion_contract']['step'] = $name;
    ($this->register)();
    $input = $this->operation->input;
    $input['descriptors'][0]['step'] = $name;
    $this->operation->update(['input' => $input]);
    $result = ['backend' => 'active', 'echo' => '[REDACTED]'];
    $this->postJson($this->path.'/run', $this->run)->assertOk();
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'finished', 'name' => $name,
        'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($this->operation->steps()->sole()->name)->toBe($name);
})->with(['source_preparation.resolve', 'setup.install', 'before_activation.install']);

it('exposes a filtered public projection and validates it on failed or cancelled completion', function (string $status): void {
    $projection = ['deployment_id' => 42, 'release' => 'new-release', 'selected_release' => 'previous-release', 'commit' => 'admitted-sha'];
    $this->spec['result_fields'] = [...$this->spec['result_fields'], ...array_keys($projection)];
    ($this->register)();
    $this->operation->update(['state' => ['executor_lease_node_id' => $this->executorNodeId, 'family_projection' => [...$projection, 'unlisted' => 'drop this']], 'cancel_requested_at' => now()]);
    $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->assertJsonPath('data.execution_context.result', $projection);
    $body = ['worker' => 'worker', 'status' => $status, 'result' => [...$projection, 'deployment_id' => 99], 'error_code' => 'deployment.failed'];
    $this->postJson($this->path.'/complete', $body)->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($this->path.'/complete', [...$body, 'result' => $projection])->assertOk()->assertJsonPath('data.result', $projection);
    expect($this->command->runs)->toBe(0);
})->with(['failed', 'cancelled']);

it('validates registered agent wrappers using native Git wire input without weakening the schema', function (bool $valid): void {
    app()->instance(KitNativeSource::class, new KitNativeSource(GitReadEnvironment::forGitHubToken('kit-private-token-value')));
    app()->tag([KitNativeSource::class], OperationSecretSource::class);
    app()->instance(OperationTransports::class, new OperationTransports(['deploy-agent' => KitAgentWrapper::class]));
    $name = 'instance.prepare-production-source';
    $input = ['repository' => 'https://github.com/example/project.git', 'user' => 'orbit', 'home' => '/home/orbit/project',
        'instance' => 27, 'allow_existing' => true, 'git_read' => ['$secret' => 'project.native', 'id' => 27]];
    if (! $valid) {
        $input['unexpected'] = 'reject this even though admitted';
    }
    $run = [...$this->run, 'transport' => 'deploy-agent', 'name' => $name, 'input' => $input];
    $admitted = $this->operation->input;
    $admitted['descriptors'][0] = [...$admitted['descriptors'][0], 'transport' => 'deploy-agent', 'name' => $name, 'input' => $input];
    $this->operation->update(['node_id' => Node::query()->where('name', 'gateway')->sole()->id, 'input' => $admitted]);
    $this->spec['runs'] = [['transport' => 'deploy-agent', 'name' => $name, 'secrets' => ['git_read' => 'project.native']]];
    ($this->register)();
    $runner = Mockery::mock(AgentCommandRunner::class);
    if ($valid) {
        $runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $command, array $actual): bool => $node->id === $this->operation->node_id
            && $command === $name && $actual['git_read'] instanceof GitReadEnvironment && $actual['git_read']->githubToken() === 'kit-private-token-value')->andReturn([]);
    } else {
        $runner->shouldNotReceive('run');
    }
    app()->instance(AgentCommandRunner::class, $runner);
    $response = $this->postJson($this->path.'/run', $run)->assertStatus($valid ? 200 : 422);
    if (! $valid) {
        $response->assertJsonPath('error.code', 'operation.execution_forbidden');
    } else {
        $this->postJson($this->path.'/run', $run)->assertOk();
        expect($this->operation->refresh()->state['executor_runs']['effect']['input'])->toBe($input);
    }
    expect(json_encode($this->operation->refresh()->toArray()))->not->toContain('kit-private-token-value');
})->with([true, false]);

it('keeps native object and list validation strict through an agent wrapper', function (bool $object): void {
    app()->instance(KitNativeSource::class, new KitNativeSource($object ? (object) [] : [], ['kit-native-map-redaction']));
    app()->tag([KitNativeSource::class], OperationSecretSource::class);
    app()->instance(OperationTransports::class, new OperationTransports(['deploy-agent' => KitAgentWrapper::class]));
    $name = 'instance.run-lifecycle-step';
    $input = ['checkout' => '/home/orbit/project/current', 'program' => ['command' => 'true'], 'step' => 'setup.check',
        'timeout_seconds' => 10, 'environment' => ['$secret' => 'project.native', 'id' => 27]];
    $run = [...$this->run, 'transport' => 'deploy-agent', 'name' => $name, 'input' => $input];
    $admitted = $this->operation->input;
    $admitted['descriptors'][0] = [...$admitted['descriptors'][0], 'transport' => 'deploy-agent', 'name' => $name, 'input' => $input];
    $this->operation->update(['node_id' => Node::query()->where('name', 'gateway')->sole()->id, 'input' => $admitted]);
    $this->spec['runs'] = [['transport' => 'deploy-agent', 'name' => $name, 'secrets' => ['environment' => 'project.native']]];
    $this->spec['result_fields'][] = 'exit_code';
    ($this->register)();
    $runner = Mockery::mock(AgentCommandRunner::class);
    if ($object) {
        $runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $command, array $actual): bool => $command === $name && $actual['environment'] instanceof stdClass)
            ->andReturn(['exit_code' => 0, 'timed_out' => false, 'output' => [], 'truncated' => false]);
    } else {
        $runner->shouldNotReceive('run');
    }
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson($this->path.'/run', $run)->assertStatus($object ? 200 : 422)->assertJsonPath($object ? 'data.exit_code' : 'error.code', $object ? 0 : 'operation.execution_forbidden');
})->with([true, false]);

it('defaults a started run without a receipt to uncertain without a second mint or effect', function (): void {
    $this->command->loseReply = true;
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $this->command->loseReply = false;
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.error_code', 'operation.execution_uncertain');
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.error_code', 'operation.execution_uncertain');
    $state = $this->operation->refresh()->state;
    expect($this->command->runs)->toBe(1)->and($this->source->resolves)->toBe(1)
        ->and($state['executor_runs']['effect']['started'])->toBeTrue()
        ->and($state['executor_runs']['effect']['result'])->toBeNull()
        ->and($state)->not->toHaveKey('projection_count');
});

it('refuses equal numeric secret owners across different target types before resolution', function (): void {
    $this->operation->update(['target_type' => 'instance']);
    $this->postJson($this->path.'/run', $this->run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->source->resolves)->toBe(0)->and($this->command->runs)->toBe(0);
});

it('returns a fixed refusal for invalid UTF-8 in a hydrated value', function (): void {
    $this->source->token = "private-invalid-\xff";
    $response = $this->postJson($this->path.'/run', $this->run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($response->getContent())->not->toContain('private-invalid');
    expect($this->command->runs)->toBe(0)
        ->and($this->operation->refresh()->state['executor_runs']['effect']['started'])->toBeFalse();
});

it('refuses permissive transport schemas and hides wire-conversion type errors', function (string $mode): void {
    $transport = new KitInvalidTransport($mode);
    app()->instance(KitInvalidTransport::class, $transport);
    app()->instance(OperationTransports::class, new OperationTransports(['invalid' => KitInvalidTransport::class]));
    $this->spec['runs'][0]['transport'] = 'invalid';
    ($this->register)();
    $input = $this->operation->input;
    $input['descriptors'][0]['transport'] = 'invalid';
    $this->operation->update(['input' => $input, 'node_id' => Node::query()->where('name', 'gateway')->sole()->id]);
    $response = $this->postJson($this->path.'/run', [...$this->run, 'transport' => 'invalid'])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($response->getContent())->not->toContain('kit-private-token-value');
    expect($transport->runs)->toBe(0)
        ->and($this->operation->refresh()->state['executor_runs']['effect']['started'])->toBeFalse();
})->with(['permissive', 'empty', 'type-error']);

it('marks all hydrated-value and redaction parameters as sensitive', function (): void {
    foreach ([
        [OperationExecutionContract::class, 'validateHydratedInput', 'input'],
        [OperationExecutionContract::class, 'result', 'knownValues'],
        [OperationSecrets::class, 'redact', 'values'],
        [AgentSchemas::class, 'wireInput', 'input'],
        [AgentCommandRunner::class, 'run', 'input'],
        [NativeAgentCommandRunner::class, 'run', 'input'],
        [NativeAgentCommandRunner::class, 'runWith', 'input'],
        [\App\Domain\RemotePrograms\RemoteProgramRunner::class, 'run', 'input'],
        [\App\Infrastructure\RemotePrograms\SshProgramRunner::class, 'run', 'input'],
    ] as [$class, $method, $name]) {
        expect((new ReflectionParameter([$class, $method], $name))->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }
});


it('collects typed agent tokens and scalar protected values for redaction', function (): void {
    $token = 'ghs_kit_secret_token';
    $value = new OperationSecretValue(new \App\Infrastructure\AgentCommands\AgentGitHubToken($token));
    expect($value->strings())->toContain($token, base64_encode('x-access-token:'.$token));
    $values = (new OperationSecretValue(['pin' => 123456, 'ratio' => 12.5, 'enabled' => true]))->strings();
    expect($values)->toContain('123456', '12.5', '1');
    $secrets = app(\App\Domain\Operations\OperationSecrets::class);
    expect($secrets->redact(['pin' => 123456, 'text' => 'pin 123456', 'ratio' => 12.5], $values))
        ->toBe(['pin' => '[REDACTED]', 'text' => 'pin [REDACTED]', 'ratio' => '[REDACTED]']);
});

it('refuses protected non-null values without redaction strings', function (string $case): void {
    $value = match ($case) { 'object' => new stdClass, 'list' => [], 'empty' => '', default => null };
    app()->instance(KitNativeSource::class, new KitNativeSource($value));
    app()->tag([KitNativeSource::class], OperationSecretSource::class);
    $hydrate = fn () => app(\App\Domain\Operations\OperationSecrets::class)->hydrate($this->operation, 'protected',
        ['payload' => ['$secret' => 'project.native', 'id' => 27]], ['payload' => 'project.native']);
    if ($case === 'null') {
        expect($hydrate())->toBe(['input' => ['payload' => null], 'values' => []]);
    } else {
        expect($hydrate)->toThrow(ResourceOperationException::class);
    }
})->with(['object', 'list', 'empty', 'null']);


it('retains failed optional step rows while clearing failed_step on successful completion', function (): void {
    $input = $this->operation->input;
    $input['descriptors'][] = [...$input['descriptors'][0], 'key' => 'optional', 'step' => 'cleanup.optional', 'required' => false];
    $this->operation->update(['input' => $input]);
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'warning', 'name' => 'cleanup.optional', 'status' => 'failed', 'error_code' => 'cleanup.warning'])->assertOk();
    $this->postJson($this->path.'/run', $this->run)->assertOk();
    $result = ['backend' => 'active', 'echo' => '[REDACTED]'];
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'success', 'name' => 'effect', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => $result])->assertOk()->assertJsonPath('data.failed_step', null);
    expect($this->operation->refresh()->failed_step)->toBeNull()
        ->and($this->operation->steps()->where('name', 'cleanup.optional')->sole()->status->value)->toBe('failed');
});
