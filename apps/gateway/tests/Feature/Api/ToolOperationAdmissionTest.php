<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolIntentProjection;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = toolOperationNode('gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->node = toolOperationNode('dev', '10.44.0.3', 209);
    config(['swamp.run_token' => 'fixture-token', 'swamp.read_token' => 'fixture-read', 'swamp.address' => 'swamp.invalid', 'swamp.ca_path' => '/fixture/ca.pem']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    $this->socket = $socket;
    app()->instance(WebSocketClient::class, $socket);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    Queue::fake();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
});

it('admits keyed installs without manager execution or premature Tool ownership', function (): void {
    $body = ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'jq', 'version_constraint' => '^1.0'];
    $start = $this->withHeader('Idempotency-Key', 'tools-proof')->postJson('/api/v1/tools', $body)->assertAccepted();
    $this->postJson('/api/v1/tools', $body)->assertAccepted()->assertJsonPath('data.id', $start->json('data.id'));
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('tool.install')->and($operation->node_id)->toBe($this->node->id)
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->input['version_constraint'])->toBe('^1.0')->and($operation->input)->not->toHaveKey('constraint_intervals')->and(Tool::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('records command activity for admission without claiming synchronous package completion', function (): void {
    $response = $this->postJson('/api/v1/tools', [
        'node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'jq',
    ])->assertAccepted();

    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $activity = Activity::query()->where('command', 'tool:install')->sole();
    expect($operation->status->value)->toBe('running')
        ->and($activity->target_node_id)->toBe($this->node->id)
        ->and($activity->subject_id)->toBeNull();
    expect(Tool::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('keeps adopt admission free of package and manager mutations', function (): void {
    $this->postJson('/api/v1/tools/adopt', ['node_id' => $this->node->id, 'manager' => 'vp', 'package' => 'is-number'])
        ->assertAccepted()->assertJsonPath('data.kind', 'tool.adopt');
    expect(Tool::query()->count())->toBe(0)->and($this->node->toolManagers()->count())->toBe(0);
    $inputs = $this->socket->sent[0]['payload']['inputs'];
    expect($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-tool-adopt-209')
        ->and($inputs['intent']['package'])->toBe('is-number')->and($inputs['ssh']['control_node_id'])->toBe(209)
        ->and($inputs['ssh']['target']['host'])->toBe('10.44.0.3')
        ->and($inputs['ssh']['target']['host_keys'])->toBe(['ssh-ed25519 '.$this->node->ssh_host_key]);
    $this->node->update(['user' => 'changed', 'wireguard_ip' => '10.44.0.99']);
    expect(app(ToolIntentProjection::class)->input(Operation::query()->sole()))->toBe(['intent' => $inputs['intent'], 'ssh' => $inputs['ssh']]);
    Queue::assertNothingPushed();
});

it('rejects unsupported packages and constraints before admission', function (string $manager, string $package, ?string $constraint, string $code): void {
    $this->postJson('/api/v1/tools', ['node_id' => $this->node->id, 'manager' => $manager, 'package' => $package, 'version_constraint' => $constraint])
        ->assertUnprocessable()->assertJsonPath('error.code', $code);
    expect(Operation::query()->count())->toBe(0);
})->with([
    ['npm', 'is-number', null, 'tool.manager_unsupported'],
    ['apt', '--help', null, 'tool.package_invalid'],
    ['brew', 'foreign/tap/package', null, 'tool.package_invalid'],
    ['vp', 'https://example.test/pkg', null, 'tool.package_invalid'],
    ['composer', 'pkg', null, 'tool.package_invalid'],
    ['apt', 'jq', 'not a range', 'tool.constraint_invalid'],
    ['apt', 'jq', '^1.0 ||', 'tool.constraint_invalid'],
    ['apt', 'jq', '>=1.0 <', 'tool.constraint_invalid'],
]);

it('protects Node prerequisites and refuses role-owned packages', function (string $manager, string $package, string $code): void {
    $this->postJson('/api/v1/tools', ['node_id' => $this->node->id, 'manager' => $manager, 'package' => $package])
        ->assertConflict()->assertJsonPath('error.code', $code);
    expect(Operation::query()->count())->toBe(0);
})->with([
    ['apt', 'docker.io', 'tool.package_protected'], ['apt', 'openssh-server', 'tool.package_protected'],
    ['apt', 'php8.5-cli', 'tool.package_role_owned'], ['vp', 'pnpm', 'tool.package_protected'],
    ['brew', 'wireguard-go', 'tool.package_protected'],
]);

it('admits update and removal against the exact recorded identity', function (string $verb): void {
    $record = $this->node->toolManagers()->create(['name' => 'vp', 'status' => LifecycleStatus::Active]);
    $tool = $this->node->tools()->create(['tool_manager_id' => $record->id, 'package' => 'is-number', 'status' => ToolStatus::Installed, 'installed_version' => '7.0.0']);
    $response = $verb === 'update'
        ? $this->postJson('/api/v1/tools/'.$tool->id.'/update')
        : $this->deleteJson('/api/v1/tools/'.$tool->id);
    $response->assertAccepted()->assertJsonPath('data.kind', 'tool.'.$verb);
    expect(Operation::query()->sole()->input['tool_id'])->toBe($tool->id)->and($tool->refresh()->status)->toBe(ToolStatus::Installed);
    Queue::assertNothingPushed();
})->with(['update', 'remove']);

it('replays a completed removal after the Tool row has gone and still enforces Node access', function (): void {
    $record = $this->node->toolManagers()->create(['name' => 'vp', 'status' => LifecycleStatus::Active]);
    $tool = $this->node->tools()->create(['tool_manager_id' => $record->id, 'package' => 'is-number', 'status' => ToolStatus::Installed]);
    $path = '/api/v1/tools/'.$tool->id;
    $response = $this->withHeader('Idempotency-Key', 'remove-proof')->deleteJson($path)->assertAccepted();
    $tool->delete();
    $operation = Operation::query()->sole();
    $operation->update(['status' => 'succeeded', 'lock_key' => null]);
    $this->deleteJson($path)->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));
    $other = toolOperationNode('other', '10.44.0.4');
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip])->deleteJson($path)->assertForbidden();
});

it('rejects malformed strict input without exposing caller fields', function (): void {
    $this->postJson('/api/v1/tools', ['node_id' => $this->node->id, 'manager' => 'vp', 'package' => 'is-number', 'script' => 'secret-sentinel'])
        ->assertUnprocessable()->assertDontSee('secret-sentinel');
    expect(Operation::query()->count())->toBe(0);
});

function toolOperationNode(string $name, string $address, ?int $id = null): Node
{
    return Node::query()->forceCreate([...($id === null ? [] : ['id' => $id]), 'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.20', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => $address,
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43)]);
}

it('projects only the selected adoption from the fixed native terminal result once', function (string $status): void {
    $this->postJson('/api/v1/tools/adopt', ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'jq'])->assertAccepted();
    $operation = Operation::query()->sole();
    $result = $status === 'succeeded' ? ['manager_version' => 'apt 3.0.0', 'installed_version' => '1.7.1', 'outcome' => 'applied'] : ['error_code' => 'tool.package_absent'];
    $run = ['id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-tool-adopt-209', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'tool.adopt'],
        'startedAt' => '2026-11-01T01:00:00Z', 'completedAt' => '2026-11-01T01:00:01Z',
        'jobs' => [['name' => 'tools', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]]]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];
    $projector = app(SwampCommandStep::class);
    $projector->projectTerminal($operation, $run, ['job' => 'tools', 'step' => 'result']);
    $projector->projectTerminal($operation, $run, ['job' => 'tools', 'step' => 'result']);
    expect($operation->fresh()->status->value)->toBe($status)->and(Tool::query()->count())->toBe($status === 'succeeded' ? 1 : 0);
    if ($status === 'succeeded') {
        expect(Tool::query()->sole()->package)->toBe('jq')->and(Tool::query()->sole()->node_id)->toBe($this->node->id)
            ->and($this->node->toolManagers()->sole()->installed_version)->toBe('apt 3.0.0');
    } else {
        expect($this->node->toolManagers()->count())->toBe(0);
    }
})->with(['succeeded', 'failed']);

it('refuses native adoption without full stored host pins before creating work', function (): void {
    $this->node->update(['ssh_host_key' => null]);
    $this->postJson('/api/v1/tools/adopt', ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'jq'])
        ->assertStatus(503)->assertJsonPath('error.code', 'swamp.ssh_target_unavailable');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('rejects the retired adoption intent and result HTTP callbacks', function (): void {
    $this->postJson('/api/v1/tools/adopt', ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'jq'])->assertAccepted();
    $operation = Operation::query()->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $run = $operation->state['swamp_run_id'];
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$run)->assertForbidden();
    $this->postJson($path.'/result', ['run_id' => $run, 'status' => 'succeeded',
        'started_at' => '2026-11-01T01:00:00.000Z', 'finished_at' => '2026-11-01T01:00:01.000Z',
        'result' => ['manager_version' => 'apt 3.0.0', 'installed_version' => '1.0.0']])->assertForbidden();
    expect(Tool::query()->count())->toBe(0);
});

it('sends bound adoption cancellation to the native root and leaves terminal projection native', function (): void {
    $this->postJson('/api/v1/tools/adopt', ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'jq'])->assertAccepted();
    $operation = Operation::query()->sole();
    Http::fake(['https://swamp.orbit/api/v1/cancel/workflow-run/*' => Http::response(['status' => 'cancelled'])]);
    app(CancelOperationAction::class)->execute($operation);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://swamp.orbit/api/v1/cancel/workflow-run/'.$operation->state['swamp_run_id']);
    expect($operation->fresh()->status->terminal())->toBeFalse()->and($operation->fresh()->cancel_requested_at)->not->toBeNull();
    expect(Tool::query()->count())->toBe(0);
});

it('retains an unconfirmed native cancellation without inventing terminal success', function (): void {
    $this->postJson('/api/v1/tools/adopt', ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'jq'])->assertAccepted();
    $operation = Operation::query()->sole();
    Http::fake(['https://swamp.orbit/api/v1/cancel/workflow-run/*' => Http::response([], 503)]);
    expect(fn () => app(CancelOperationAction::class)->execute($operation))->toThrow(ResourceOperationException::class);
    expect($operation->fresh()->status->terminal())->toBeFalse()->and($operation->fresh()->cancel_requested_at)->not->toBeNull();
    expect(Tool::query()->count())->toBe(0);
});
