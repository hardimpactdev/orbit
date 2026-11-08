<?php

declare(strict_types=1);

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeConvergeScope;
use App\Domain\Nodes\NodeNetworkReservation;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    config(['swamp.run_token' => 'test-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $this->frames = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame): void { $this->frames[] = $frame; });
    $socket->shouldReceive('receive')->andReturnUsing(function (): array {
        $frame = end($this->frames);
        if ($frame['type'] === 'vault.put') {
            return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }
        return [['type' => 'event', 'id' => $frame['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-node-converge', 'runId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]];
    });
    app()->instance(WebSocketClient::class, $socket);
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $this->manifest = tmpfile();
    fwrite($this->manifest, json_encode(['commit' => str_repeat('a', 40), 'version' => '1.2.3', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery(stream_get_meta_data($this->manifest)['uri'], '/unused'));
    $this->control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $this->control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->control->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node = Node::withoutEvents(function (): Node {
        $node = Node::query()->create(['name' => 'target', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned']);
        $node->forceFill(['ssh_host_fingerprint' => 'SHA256:pinned', 'agent_secret_hash' => hash('sha256', 'private-test-agent-secret')])->save();
        return $node;
    });
    $this->control->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->control->wireguard_ip]);
    $this->url = '/api/v1/nodes/'.$this->node->id.'/converge';
});

afterEach(function (): void {
    app(SwampConnection::class)->release();
    fclose($this->manifest);
});

it('captures base from recorded state, redacts its verifier and dispatches copies before exactly one workflow', function (): void {
    $role = $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'failed', 'error_code' => 'earlier']);
    $before = $role->refresh()->getAttributes();
    $response = $this->postJson($this->url, ['base' => true])->assertAccepted();
    $operation = Operation::query()->sole();
    $capture = $operation->input['_converge'];
    expect($capture['intent']['selected_roles'])->toBe([])
        ->and($capture['intent']['roles'])->toBe([])->and($capture['intent']['tools'])->toBe([])->and($capture['intent']['network'])->toBeNull()
        ->and($capture['intent']['agent'])->toMatchArray(['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent'])
        ->and($capture['execution_secrets']['agent_verifier']['key'])->toBe('node-converge-operation-'.$operation->id.'-agent-verifier')
        ->and(array_column($this->frames, 'type'))->toBe(['vault.put', 'workflow.run'])
        ->and($this->frames[1]['payload']['workflowIdOrName'])->toBe('operation-node-converge')
        ->and($this->frames[1]['payload']['inputs']['intent'])->toBe($capture['intent'])
        ->and(json_encode($capture))->not->toContain(hash('sha256', 'private-test-agent-secret'))
        ->and($response->getContent())->not->toContain('private-test-agent-secret')->not->toContain('execution_secrets')
        ->and($role->refresh()->getAttributes())->toBe($before);
    Http::assertNothingSent();
    Process::assertNothingRan();
});

it('replays an idempotent start without recapturing private input or dispatching again', function (): void {
    $first = $this->withHeader('Idempotency-Key', 'node-converge-replay')->postJson($this->url, ['base' => true])->assertAccepted();
    $frames = count($this->frames);
    $again = $this->postJson($this->url, ['base' => true])->assertAccepted();
    expect($again->json('data.id'))->toBe($first->json('data.id'))->and(count($this->frames))->toBe($frames)->and(Operation::query()->count())->toBe(1);
});

it('rejects invalid scope and removed flags before any operation or private copy', function (array $input): void {
    $this->postJson($this->url, $input)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(Operation::query()->count())->toBe(0)->and($this->frames)->toBe([]);
})->with([
    'conflicting' => [['base' => true, 'roles' => ['app-prod']]],
    'empty' => [['roles' => []]], 'unassigned' => [['roles' => ['app-prod']]], 'unknown' => [['roles' => ['unknown']]],
    'force' => [['force' => true]], 'full' => [['full' => true]], 'worker credentials' => [['worker_credentials' => true]],
    'duplicate' => [['roles' => ['app-prod', 'app-prod']]],
]);

it('requires Node access before admitting work', function (): void {
    $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'no-access', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->postJson($this->url, ['base' => true])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    expect(Operation::query()->count())->toBe(0)->and($this->frames)->toBe([]);
});

it('refuses unsupported Nodes from recorded eligibility with no live probe', function (array $attributes): void {
    Node::withoutEvents(fn () => $this->node->forceFill($attributes)->save());
    $this->postJson($this->url, ['base' => true])->assertUnprocessable()->assertJsonPath('error.code', 'node.converge_unsupported');
    expect(Operation::query()->count())->toBe(0)->and($this->frames)->toBe([]);
})->with(['inactive' => [['status' => 'failed']], 'macOS' => [['platform' => 'macos']], 'unmanaged' => [['ssh_host_fingerprint' => null]]]);

it('reserves the target and the recorded shared hub even for a base-only scope', function (): void {
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->input['_converge']['network'])->toBe(['hub_id' => $this->control->id]);
    expect(fn () => app(NodeNetworkReservation::class)->hold($this->control, fn () => null))->toThrow(ResourceOperationException::class, 'Another Operation owns the Node or VPN hub.');
    $this->postJson($this->url, ['base' => true])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
});

it('admits a fresh attempt after failure without trusting an earlier footprint digest', function (): void {
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $operation = Operation::query()->sole();
    $operation->update(['status' => OperationStatus::Failed, 'lock_key' => null]);
    $second = app(StartNodeConvergeAction::class)->execute($this->node, true);
    expect($second->id)->not->toBe($operation->id)->and($second->input['_converge']['intent']['agent'])->toBe($operation->input['_converge']['intent']['agent']);
});

it('selects only existing assignments without changing their status or settings', function (): void {
    $a = $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $b = $this->node->roles()->create(['role' => RoleName::Metrics, 'status' => 'failed']);
    expect(NodeConvergeScope::roles($this->node, false, null))->toBe(['app-dev', 'metrics'])
        ->and(NodeConvergeScope::roles($this->node, false, ['metrics']))->toBe(['metrics'])
        ->and(NodeConvergeScope::roles($this->node, true, null))->toBe([])
        ->and($a->refresh()->status->value)->toBe('active')->and($b->refresh()->status->value)->toBe('failed');
});
