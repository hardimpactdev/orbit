<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationGatewayCommands;
use App\Domain\Operations\OperationGatewayTransport;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;

final class KitSecretSource implements OperationSecretSource
{
    public int $resolves = 0;

    public bool $missingOwner = false;

    public bool $rotates = false;

    public ?ResourceOperationException $refusal = null;

    public function name(): string
    {
        return 'project.test';
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

        return new OperationSecretValue('kit-private-token-value'.($this->rotates ? '-'.$this->resolves : ''));
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

final class KitEchoCommand implements OperationGatewayCommand
{
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        return ['echo' => 'kit-private-token-value', 'backend' => 'active'];
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
        return ['descriptors' => $operation->input['descriptors']];
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

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

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
    app()->instance(OperationGatewayCommands::class, new OperationGatewayCommands(['test.command' => KitGatewayCommand::class, 'test.echo' => KitEchoCommand::class]));
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
        'status' => OperationStatus::Running, 'worker' => 'worker', 'sequence' => 1,
        'input' => ['configuration' => ['ports' => [65431]], 'descriptors' => [[...array_diff_key($this->run, ['worker' => true]), 'step' => 'effect', 'resumable' => true, 'required' => true]]]]);
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
});

it('hydrates target-owned references only in memory and stores one redacted Gateway receipt and projection', function (): void {
    $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->assertJsonPath('data.execution_context.descriptors.0.input.token.$secret', 'project.test');
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.echo', '[REDACTED]')->assertJsonMissingPath('data.undeclared');
    $this->postJson($this->path.'/run', $this->run)->assertOk();
    expect($this->command->runs)->toBe(1);
    expect($this->operation->refresh()->state['projection_count'])->toBe(1);
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'finished', 'name' => 'effect', 'status' => 'succeeded',
        'result' => ['backend' => 'active', 'echo' => '[REDACTED]'], 'output' => 'echoed kit-private-token-value'])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => ['backend' => 'active', 'echo' => '[REDACTED]']])->assertOk();
    expect(json_encode([$this->operation->refresh()->toArray(), $this->operation->steps()->get()->toArray()]))->not->toContain('kit-private-token-value');
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
    $this->operation->update(['input' => $input, 'state' => $state, 'cancel_requested_at' => now()]);
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

it('redacts earlier successful failed or uncertain secrets in a later unprotected receipt', function (string $outcome): void {
    $input = $this->operation->input;
    $input['descriptors'][] = ['key' => 'echo', 'step' => 'echo', 'name' => 'test.echo', 'transport' => 'gateway', 'input' => [], 'resumable' => true, 'required' => false];
    $this->operation->update(['input' => $input]);
    $this->spec['runs'][] = ['transport' => 'gateway', 'name' => 'test.echo'];
    ($this->register)();
    $this->command->loseReply = $outcome === 'uncertain';
    $this->command->refuse = $outcome === 'failed';
    $this->postJson($this->path.'/run', $this->run)->assertStatus($outcome === 'uncertain' ? 502 : 200);
    $this->postJson($this->path.'/run', [...$this->run, 'key' => 'echo', 'name' => 'test.echo', 'input' => []])->assertOk()->assertJsonPath('data.echo', '[REDACTED]');
    expect(json_encode($this->operation->refresh()->state))->not->toContain('kit-private-token-value');
})->with(['success', 'failed', 'uncertain']);

it('binds run keys and receipts to references when each mint returns a new token', function (): void {
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
        ->and($this->operation->refresh()->state)->toBeNull();
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
