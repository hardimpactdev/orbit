<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationGatewayCommands;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationSecretOutputs;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\WebSocketApp;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;

final class OutputStore implements OperationSecretOutputStore
{
    public int $calls = 0;

    public bool $missingOwner = false;

    public bool $wrongType = false;

    public bool $fail = false;

    public bool $acceptBoolean = false;

    public function name(): string
    {
        return 'test.owner.credential';
    }

    public function targetTypes(): array
    {
        return $this->wrongType ? ['instance'] : ['websocket_app'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $this->missingOwner ? null : WebSocketApp::query()->find($operation->target_id)?->id;
    }

    public function store(Operation $operation, int $ownerId, #[SensitiveParameter] mixed $value): void
    {
        $this->calls++;
        if ($this->fail) {
            throw new ResourceOperationException('test.output_refused', 'private-output-credential-value', 409, ['credential' => $value]);
        }
        if ($this->acceptBoolean && is_bool($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }
        if (! is_string($value)) {
            throw new LogicException('Expected a string credential.');
        }
        WebSocketApp::query()->findOrFail($ownerId)->update(['secret' => $value]);
    }
}
final class OutputCommand implements OperationGatewayCommand
{
    public int $calls = 0;

    public mixed $value = 'private-output-credential-value';

    public bool $dropOwner = false;

    public bool $badSchema = false;

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, ...($this->badSchema ? ['required' => ['missing']] : [])];
    }

    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $this->calls++;
        if ($this->dropOwner) {
            WebSocketApp::query()->whereKey($operation->target_id)->delete();
        }

        return ['backend' => 'issued', 'credential' => $this->value, 'echo' => is_bool($this->value) ? $this->value : 'echo:'.(is_string($this->value) ? $this->value : 'other')];
    }
}
final class OutputFamily implements OperationCompletion, OperationFamilyExecution
{
    public bool $failProjection = false;

    public array $received = [];

    public function context(Operation $operation): array
    {
        return ['descriptors' => [['key' => 'issue', 'step' => 'issue', 'transport' => 'gateway', 'name' => 'test.issue', 'input' => [], 'resumable' => false, 'required' => true]], ...(isset($operation->state['public_projection']) ? ['result' => $operation->state['public_projection']] : [])];
    }

    public function input(Operation $operation, array $run): array
    {
        return [];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        $this->received = $receipt;
        $operation->forceFill(['state' => [...($operation->state ?? []), 'public_projection' => $receipt]])->save();
        if ($this->failProjection) {
            throw new ResourceOperationException('test.projection_refused', 'Projection refused.', 409);
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.1']);
    $this->gateway = $gateway;
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => 'active']);
    $services = Node::query()->create(['name' => 'services', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.17']);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->owner = WebSocketApp::query()->create(['name' => 'output-fixture', 'app_id' => 'output-fixture', 'key' => 'output-fixture', 'secret' => 'original']);
    $this->store = new OutputStore;
    $this->command = new OutputCommand;
    $this->family = new OutputFamily;
    app()->instance(OutputCommand::class, $this->command);
    app()->instance(OutputFamily::class, $this->family);
    app()->instance(OperationGatewayCommands::class, new OperationGatewayCommands(['test.issue' => OutputCommand::class]));
    $this->definition = ['executor' => 'swamp', 'completion' => OutputFamily::class, 'execution' => OutputFamily::class,
        'runs' => [['transport' => 'gateway', 'name' => 'test.issue']],
        'completion_contract' => ['step' => 'issue', 'transport' => 'gateway', 'name' => 'test.issue', 'result' => ['backend' => 'issued']],
        'result_fields' => ['backend', 'echo', 'error_code', ['name' => 'credential', 'secret' => true, 'store' => 'test.owner.credential']]];
    $kinds = new OperationKinds(app(), [...OperationKinds::definitions(), 'test.secret_output' => $this->definition]);
    app()->instance(OperationKinds::class, $kinds);
    app()->instance(OperationSecretOutputs::class, new OperationSecretOutputs([$this->store], $kinds));
    $this->operation = app(StartOperationAction::class)->execute('test.secret_output', 'websocket_app', $this->owner->id, null, [], 'test', null);
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.secret_output', 'worker' => 'outputs'])->assertOk();
    $this->run = ['worker' => 'outputs', 'key' => 'issue', 'transport' => 'gateway', 'name' => 'test.issue', 'input' => (object) []];
    $this->reference = ['$secret' => 'test.owner.credential', 'id' => $this->owner->id];
});

it('encrypts outputs once and keeps only references through hook, step, context and completion', function (): void {
    $response = $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.credential', $this->reference);
    expect($response->getContent())->not->toContain($this->command->value)
        ->and($this->family->received['credential'])->toBe($this->reference)
        ->and($this->family->received['echo'])->toBe('echo:[REDACTED]')
        ->and($this->owner->fresh()->secret)->toBe($this->command->value)
        ->and(DB::table('websocket_apps')->where('id', $this->owner->id)->value('secret'))->not->toContain($this->command->value);
    $result = $response->json('data');
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data', $result);
    $this->postJson($this->path.'/step', ['worker' => 'outputs', 'key' => 'issue.done', 'name' => 'issue', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($this->path.'/heartbeat', ['worker' => 'outputs'])->assertOk()->assertJsonPath('data.execution_context.result.credential', $this->reference);
    $this->postJson($this->path.'/complete', ['worker' => 'outputs', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'outputs', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($this->store->calls)->toBe(1)->and($this->command->calls)->toBe(1)
        ->and(json_encode($this->operation->fresh()->getAttributes()))->not->toContain($this->command->value)
        ->and(json_encode($this->operation->steps()->get()->toArray()))->not->toContain($this->command->value)
        ->and(OperationKinds::supports('secret_outputs'))->toBeTrue();
});

it('refuses unavailable output owners and stores before invoking the command', function (string $case): void {
    if ($case === 'missing') {
        $this->store->missingOwner = true;
    } elseif ($case === 'type') {
        $this->store->wrongType = true;
    } else {
        app()->instance(OperationSecretOutputs::class, new OperationSecretOutputs($case === 'unknown' ? [] : [$this->store, $this->store], app(OperationKinds::class)));
    }
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.secret_output_unavailable');
    expect($this->command->calls)->toBe(0)->and($this->store->calls)->toBe(0)->and($this->owner->fresh()->secret)->toBe('original');
})->with(['missing', 'type', 'unknown', 'duplicate']);

it('rolls back encrypted output, projection and receipt together on hook failure', function (): void {
    $this->family->failProjection = true;
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'test.projection_refused');
    expect($this->owner->fresh()->secret)->toBe('original')->and($this->operation->fresh()->state['public_projection'] ?? null)->toBeNull()
        ->and($this->operation->fresh()->state['executor_runs']['issue']['result'])->toBeNull();
});

it('rechecks ownership and refuses invalid or unencodable outputs with no stored plaintext', function (string $case): void {
    if ($case === 'gone') {
        $this->command->dropOwner = true;
    } elseif ($case === 'encoding') {
        $this->command->value = "invalid\xffcredential";
    } elseif ($case === 'oversize') {
        $this->command->value = str_repeat('x', 262145);
    } else {
        $this->store->fail = true;
    }
    $response = $this->postJson($this->path.'/run', $this->run)->assertConflict();
    expect($response->getContent())->not->toContain('private-output-credential-value')
        ->and($this->operation->fresh()->state['executor_runs']['issue']['result'])->toBeNull()
        ->and($this->operation->fresh()->state['public_projection'] ?? null)->toBeNull();
})->with(['gone', 'encoding', 'oversize', 'adapter']);

it('refuses raw credentials or forged reference owners in public completion proof', function (string $case): void {
    $result = $this->postJson($this->path.'/run', $this->run)->assertOk()->json('data');
    if ($case === 'raw') {
        $result['credential'] = 'forged-private-credential';
    } else {
        $result['credential']['id']++;
    }
    $response = $this->postJson($this->path.'/step', ['worker' => 'outputs', 'key' => 'forged', 'name' => 'issue', 'status' => 'succeeded', 'result' => $result]);
    $response->assertStatus($case === 'raw' ? 422 : 409)->assertJsonPath('error.code', $case === 'raw' ? 'operation.secret_output_unproven' : 'operation.completion_unproven');
    expect($this->store->calls)->toBe(1);
})->with(['raw', 'reference']);

it('refuses declarations whose transport could persist secret outputs before extraction', function (): void {
    $this->definition['runs'][0]['transport'] = 'remote_program';
    $this->definition['runs'][0]['name'] = 'agent.status';
    expect(fn () => new OperationKinds(app(), ['test.unsafe_output' => $this->definition]))->toThrow(LogicException::class, 'Secret outputs require a transport');
});

it('rechecks owner authorization before an unstarted retry can dispatch', function (): void {
    $this->command->badSchema = true;
    $this->postJson($this->path.'/run', $this->run)->assertStatus(422);
    expect($this->operation->fresh()->state['executor_runs']['issue']['started'])->toBeFalse();
    $this->command->badSchema = false;
    $this->store->missingOwner = true;
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.secret_output_unavailable');
    expect($this->command->calls)->toBe(0)->and($this->store->calls)->toBe(0);
});

it('refuses malformed secret output declarations at kind load', function (array $field): void {
    $this->definition['result_fields'] = [$field];
    expect(fn () => new OperationKinds(app(), ['test.invalid_output' => $this->definition]))->toThrow(LogicException::class);
})->with([
    'disabled flag' => [['name' => 'credential', 'secret' => false, 'store' => 'test.owner.credential']],
    'no store' => [['name' => 'credential', 'secret' => true]],
    'invalid store' => [['name' => 'credential', 'secret' => true, 'store' => 'test..credential']],
    'unknown rule' => [['name' => 'credential', 'secret' => true, 'store' => 'test.owner.credential', 'type' => 'string']],
]);

function startSecretAgentOutput($test): Operation
{
    $fields = ['secret_hash', 'configuration', 'certificate', 'unit', 'checkouts', 'binary_changed'];
    $definition = [...array_diff_key($test->definition, ['execution' => true]),
        'runs' => [['transport' => 'agent', 'name' => 'agent.configure', 'input_fields' => $fields]],
        'completion_contract' => ['step' => 'configure', 'transport' => 'agent', 'name' => 'agent.configure', 'result' => ['restarted' => true]],
        'result_fields' => ['restarted', ['name' => 'unclosed', 'type' => 'list', 'items' => 'string', 'max' => 32],
            ['name' => 'secret_hash', 'secret' => true, 'store' => 'test.owner.credential']]];
    $kinds = new OperationKinds(app(), [...OperationKinds::definitions(), 'test.secret_output' => $test->definition, 'test.agent_output' => $definition]);
    app()->instance(OperationKinds::class, $kinds);
    app()->instance(OperationSecretOutputs::class, new OperationSecretOutputs([$test->store], $kinds));
    $test->owner = WebSocketApp::query()->create(['name' => 'agent-output', 'app_id' => 'agent-output', 'key' => 'agent-output', 'secret' => 'original']);
    $test->input = ['secret_hash' => null, 'configuration' => '', 'certificate' => '', 'unit' => '', 'checkouts' => [], 'binary_changed' => false];
    $operation = app(StartOperationAction::class)->execute('test.agent_output', 'websocket_app', $test->owner->id, $test->gateway->id, $test->input, 'test', null);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.agent_output', 'worker' => 'agent-output'])->assertOk();
    $test->path = '/api/v1/operation-executor/'.$operation->id;
    $test->run = ['worker' => 'agent-output', 'key' => 'configure', 'transport' => 'agent', 'name' => 'agent.configure', 'input' => $test->input];

    return $operation;
}

it('binds agent.configure output stripping in protected metadata and encrypts the live hash', function (): void {
    $operation = startSecretAgentOutput($this);
    $hash = hash('sha256', 'fixture-agent-secret');
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', function (array $input) use ($hash): array {
        $effect = $input[EffectReceiptMetadata::InputKey];
        expect($effect->envelope()['secret_outputs'])->toBe(['secret_hash']);

        return ['secret_hash' => $hash, 'restarted' => true, 'unclosed' => []];
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.secret_hash', ['$secret' => 'test.owner.credential', 'id' => $this->owner->id]);
    expect($this->owner->fresh()->secret)->toBe($hash)->and(json_encode($operation->fresh()->state))->not->toContain($hash)
        ->and(DB::table('websocket_apps')->where('id', $this->owner->id)->value('secret'))->not->toContain($hash);
});

it('keeps a presence-only recovered agent output uncertain without writing or projecting', function (): void {
    $operation = startSecretAgentOutput($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', function (): array {
        throw new RuntimeException('lost live reply');
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $identity = $operation->fresh()->state['executor_effect_identity'];
    $agents->answer('agent.effect-receipt', ['command' => 'agent.configure', 'effect_key' => 'op-'.$operation->id.'.configure', 'state' => 'succeeded',
        'sanitized' => true, 'effect_nonce' => $identity['nonce'], 'effect_created_at' => $identity['created_at'], 'exit_code' => 0,
        'result' => ['ok' => true, 'data' => ['secret_hash' => ['$secret_output' => true], 'restarted' => true, 'unclosed' => []]],
        'result_digest' => str_repeat('c', 64), 'finished_at' => '2026-10-29T02:00:00Z']);
    $this->getJson($this->path.'/receipts/configure')->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    expect($operation->fresh()->state['executor_runs']['configure']['result'])->toBeNull()->and($this->store->calls)->toBe(0)
        ->and($this->owner->fresh()->secret)->toBe('original')->and($agents->commands())->toBe(['agent.configure', 'agent.effect-receipt']);
});

it('preserves declared reference fields when their secret equals the public field name', function (): void {
    $this->command->value = 'credential';
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.credential', $this->reference)->assertJsonPath('data.echo', 'echo:[REDACTED]');
    expect($this->owner->fresh()->secret)->toBe('credential');
});

it('redacts generated boolean echoes while preserving the owning record reference', function (): void {
    $this->store->acceptBoolean = true;
    $this->command->value = true;
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.credential', $this->reference)->assertJsonPath('data.echo', '[REDACTED]');
    expect($this->owner->fresh()->secret)->toBe('true');
});
