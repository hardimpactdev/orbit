<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Actions\Tools\ConvergeToolManagerAction;
use App\Actions\Tools\StartToolOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'swamp', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.17', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->node = Node::query()->forceCreate(['public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'id' => 209, 'name' => 'private-dev', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active]);
    $this->input = ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'jq', 'version_constraint' => null, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64'];
    $this->operation = Operation::query()->create(['kind' => 'tool.install', 'target_type' => 'node_tools', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'state' => ['tool_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'input' => $this->input]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->body = ['run_id' => $this->runId, 'started_at' => '2026-10-30T12:00:00.000Z'];
});

it('returns typed intent without command descriptors and requires the executor peer', function (): void {
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.manager', 'apt')->assertJsonPath('data.input.user', 'orbit');
    expect($this->operation->fresh()->state)->toBe(['tool_writer' => 'direct', 'swamp_run_id' => $this->runId]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
});

it('projects a selected package and manager without execution receipts', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'running'])->assertOk();
    $finished = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['manager_version' => 'apt 3.0.0', 'installed_version' => '1.7.1', 'outcome' => 'applied', 'password' => 'discard-this']];
    $this->postJson($this->path.'/result', $finished)->assertOk();
    $this->postJson($this->path.'/result', $finished)->assertOk();
    expect(Tool::query()->sole()->installed_version)->toBe('1.7.1');
    expect(ToolManagerRecord::query()->sole()->installed_version)->toBe('apt 3.0.0');
    expect($this->operation->fresh()->result)->not->toHaveKey('password');
    expect($this->operation->fresh()->state)->toHaveKeys(['tool_writer', 'swamp_run_id', 'steps'])->not->toHaveKey('executor_runs');
    expect($this->operation->fresh()->worker)->toBeNull();
});

it('rejects missing manager readiness absent install versions and changed run identity', function (): void {
    $body = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T12:00:01.000Z'];
    $this->postJson($this->path.'/result', [...$body, 'result' => ['installed_version' => '1.7.1']])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$body, 'result' => ['manager_version' => 'apt 3.0.0', 'installed_version' => null]])->assertConflict();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
    $this->operation->update(['state' => ['tool_writer' => 'direct', 'swamp_run_id' => 'f4c20822-d85f-4212-a60d-a81a52c30baa']]);
    $this->postJson($this->path.'/result', [...$body, 'result' => ['manager_version' => 'apt 3.0.0', 'installed_version' => '1.7.1']])->assertConflict();
    expect(Tool::query()->count())->toBe(0);
});

it('projects manager refusal without synthesizing Gateway executor state', function (): void {
    $this->operation->update(['kind' => 'tool.manager_converge', 'input' => [...$this->input, 'manager' => 'vp']]);
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'failed', 'finished_at' => '2026-10-30T12:00:01.000Z', 'error_code' => 'tool.manager_unavailable', 'result' => ['error_code' => 'tool.manager_unavailable', 'native_error_code' => 'tool.vp_scope_absent']])->assertOk();
    expect(ToolManagerRecord::query()->sole()->error_code)->toBe('node.tool_manager_absent');
    expect($this->operation->fresh()->state)->not->toHaveKey('executor_runs');
});

it('isolates kind switches retains old unstamped work and excludes direct claims', function (): void {
    foreach (['tool.install', 'tool.update', 'tool.remove', 'tool.adopt', 'tool.manager_converge'] as $kind) {
        expect(app(DirectOperation::class)->writer($kind))->toBe('polled');
    }
    config(['swamp.direct_writers' => ['tool.install' => 'direct']]);
    expect(app(DirectOperation::class)->writer('tool.update'))->toBe('polled');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'tool.install', 'worker' => 'old', 'operation_id' => $this->operation->id])->assertOk()->assertJsonPath('data', null);
    $this->operation->update(['state' => []]);
    expect(DirectOperation::owns($this->operation))->toBeFalse();
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
});

it('starts a direct run after intent commits without a target hold', function (): void {
    config(['swamp.direct_writers' => ['tool.install' => 'direct'], 'swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-tool-direct', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    DB::beginTransaction();
    $operation = app(StartOperationAction::class)->execute('tool.install', 'node_tools', $this->node->id, $this->node->id, $this->input, 'operator', null);
    expect($operation->lock_key)->toBeNull();
    expect($socket->sent)->toBe([]);
    DB::commit();
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-tool-direct');
    config(['swamp.direct_writers' => ['tool.install' => 'polled']]);
    expect(DirectOperation::owns($operation->fresh()))->toBeTrue();
});

it('records direct manager recipe data without a Gateway command descriptor', function (): void {
    $input = app(ToolManagerIntent::class)->input($this->node, ToolManagerName::Composer, direct: true);
    expect($input)->toBe(['materialize' => ['user' => 'orbit']]);
    expect($input)->not->toHaveKeys(['materialize_command', 'materialize_fields']);
});

it('refuses first read until Gateway binds its start response and exposes only safe intent', function (): void {
    config(['swamp.direct_writers' => ['tool.install' => 'direct'], 'swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = function (array $request): array {
        $id = $request['payload']['inputs']['operation_id'];
        $this->getJson('/api/v1/direct-operations/'.$id.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-tool-direct', 'runId' => $this->runId]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(StartOperationAction::class)->execute('tool.install', 'node_tools', $this->node->id, $this->node->id, [...$this->input, 'materialize_command' => 'private', 'private' => 'discard'], 'operator', null);
    $response = $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk();
    expect($response->json('data.input'))->not->toHaveKeys(['materialize_command', 'private']);
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/primitive', ['run_id' => $this->runId])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('allows only the admitted Node among per-Node workers', function (): void {
    config(['operation_executor.worker_node_ids' => [$this->node->id, $this->worker->id]]);
    $gateway = Node::query()->where('name', 'gateway')->firstOrFail();
    $this->node->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
    config(['dependency_detection.services_node_id' => null]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'running'])->assertForbidden();
});

it('rejects malformed versions and refusals without projecting records', function (array $result): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => $result])->assertUnprocessable();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
    expect(Tool::query()->count())->toBe(0);
    expect(ToolManagerRecord::query()->count())->toBe(0);
})->with([
    'empty manager version' => [['manager_version' => '', 'installed_version' => '1.0.0']],
    'raw output as version' => [['manager_version' => "3.0.0\nraw", 'installed_version' => '1.0.0']],
    'oversized package version' => [['manager_version' => '3.0.0', 'installed_version' => str_repeat('a', 256)]],
    'non-string package version' => [['manager_version' => '3.0.0', 'installed_version' => []]],
    'native refusal on success' => [['manager_version' => '3.0.0', 'installed_version' => '1.0.0', 'native_error_code' => 'tool.failed']],
    'unknown outcome' => [['manager_version' => '3.0.0', 'installed_version' => '1.0.0', 'outcome' => 'raw']],
]);

it('projects exact removal idempotently and keeps manager intent', function (): void {
    $record = ToolManagerRecord::query()->create(['node_id' => $this->node->id, 'name' => 'apt', 'status' => LifecycleStatus::Active]);
    $tool = Tool::query()->create(['node_id' => $this->node->id, 'tool_manager_id' => $record->id, 'package' => 'jq', 'status' => 'installed', 'installed_version' => '1.0.0']);
    $this->operation->update(['kind' => 'tool.remove', 'input' => [...$this->input, 'tool_id' => $tool->id]]);
    $body = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['manager_version' => '3.0.0', 'installed_version' => null]];
    $this->postJson($this->path.'/result', $body)->assertOk();
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($tool->fresh())->toBeNull();
    expect($record->fresh()->status)->toBe(LifecycleStatus::Active);
});

it('admits direct package and manager intents with immutable account pin and recipe data', function (): void {
    config(['swamp.direct_writers' => ['tool.install' => 'direct', 'tool.manager_converge' => 'direct'], 'swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-tool-direct', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43)]);
    $operation = app(StartToolOperationAction::class)->execute('install', $this->node->id, 'composer', 'vendor/package', null);
    expect($operation->input['user'])->toBe('orbit');
    expect($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->node));
    expect($operation->input['materialize'])->toBe(['user' => 'orbit']);
    expect($operation->input)->not->toHaveKeys(['materialize_command', 'materialize_fields']);
    expect($operation->lock_key)->toBeNull();
    $manager = app(ConvergeToolManagerAction::class)->execute($this->node, ToolManagerName::Apt);
    expect($manager->input)->not->toHaveKey('materialize');
    expect($manager->input)->not->toHaveKeys(['materialize_command', 'materialize_fields']);
    expect($manager->state['tool_writer'])->toBe('direct');
    $this->node->update(['user' => 'changed']);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.user', 'orbit');
});

it('selects only proven APT root intents and keeps other managers Nodes children and privileged kinds polled', function (): void {
    config(['swamp.direct_writers' => ['tool.adopt' => 'direct', 'tool.manager_converge' => 'direct']]);
    $direct = app(DirectOperation::class);
    foreach (['tool.adopt', 'tool.manager_converge'] as $kind) {
        expect($direct->writer($kind, ['manager' => 'apt', 'tool_status' => 'installed'], 209))->toBe('direct');
        foreach (['vp', 'composer', 'brew', 'brew-cask'] as $manager) {
            expect($direct->writer($kind, ['manager' => $manager], 209))->toBe('polled');
        }
        expect($direct->writer($kind, ['manager' => 'apt', 'node_id' => 209], 210))->toBe('polled');
        expect($direct->writer($kind, [], 209))->toBe('polled');
    }
    foreach (['tool.install', 'tool.update', 'tool.remove'] as $kind) {
        expect($direct->writer($kind, ['manager' => 'apt'], 209))->toBe('polled');
    }
    $idempotency = app(OperationIdempotency::class);
    $idempotency->parent = $this->operation;
    expect($direct->writer('tool.manager_converge', ['manager' => 'apt', 'parent_operation_id' => null], 209))->toBe('polled');
});

it('admits APT adoption directly while recording other managers as polled and retaining rollback per intent', function (): void {
    config(['swamp.direct_writers' => ['tool.adopt' => 'direct', 'tool.manager_converge' => 'direct'], 'swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-tool-direct', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43)]);
    $record = $this->node->toolManagers()->create(['name' => 'apt', 'status' => 'active']);
    $this->node->tools()->create(['tool_manager_id' => $record->id, 'package' => 'jq', 'status' => 'installed', 'installed_version' => '1.7.1']);
    $apt = app(StartToolOperationAction::class)->execute('adopt', 209, 'apt', 'jq', null);
    expect($apt->state['tool_writer'])->toBe('direct');
    expect($apt->lock_key)->toBeNull();
    expect($apt->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->node));
    expect($socket->sent)->toHaveCount(1);
    $other = app(ConvergeToolManagerAction::class)->execute($this->node, ToolManagerName::Composer);
    expect($other->state['tool_writer'])->toBe('polled');
    expect($other->input)->toHaveKeys(['materialize_command', 'materialize_fields'])->not->toHaveKey('agent_sha256');
    expect($socket->sent)->toHaveCount(1);
    config(['swamp.direct_writers' => ['tool.adopt' => 'polled', 'tool.manager_converge' => 'polled']]);
    expect(DirectOperation::owns($apt->fresh()))->toBeTrue();
    expect(app(DirectOperation::class)->writer('tool.adopt', ['manager' => 'apt'], 209))->toBe('polled');
});
