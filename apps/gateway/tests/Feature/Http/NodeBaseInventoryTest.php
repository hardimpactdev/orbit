<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeBaseInventoryProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Process as ProcessModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

function inventoryNode(int $id, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->forceCreate([
        'id' => $id, 'name' => 'inventory-'.$id, 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => $ip, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'fixture-fingerprint', 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => 'pinned', 'architecture' => 'x86_64',
        'agent_secret_hash' => hash('sha256', 'private-agent-fixture'),
    ]));
}

describe('rollout base inventory HTTP admission', function (): void {
    beforeEach(function (): void {
        $this->caller = inventoryNode(2, '10.44.0.2');
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    });

    it('refuses callers without target access for each protected Node', function (int $id): void {
        inventoryNode($id, '10.44.0.3');
        $this->call('POST', "/api/v1/nodes/{$id}/base-inventory", server: ['HTTP_ACCEPT' => 'application/json'])
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        expect(Operation::query()->count())->toBe(0);
    })->with([4, 17, 209]);

    it('refuses query and raw body inputs even for a granted target', function (string $query, ?string $body): void {
        $target = inventoryNode(17, '10.44.0.3');
        $this->caller->accessibleNodes()->attach($target);
        $this->call('POST', '/api/v1/nodes/17/base-inventory'.$query, server: [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        ], content: $body)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(Operation::query()->count())->toBe(0);
    })->with([
        'path query' => ['?path=/etc/shadow', null],
        'malformed JSON' => ['', '{'],
        'empty object' => ['', '{}'],
        'plain text' => ['', 'hostname'],
    ]);

    it('refuses a granted Node outside the fixed rollout', function (): void {
        $target = inventoryNode(21, '10.44.0.3');
        $this->caller->accessibleNodes()->attach($target);
        $this->call('POST', '/api/v1/nodes/21/base-inventory', server: ['HTTP_ACCEPT' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'node.base_inventory_unsupported');
        expect(Operation::query()->count())->toBe(0);
    });

    it('records one Node-bound intent and keeps the verifier in the private vault frame', function (int $id, string $profile): void {
        Queue::fake();
        config([
            'swamp.run_token' => 'test-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test',
            'swamp.ca_path' => '/test/ca.pem',
            'swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)),
        ]);
        $frames = [];
        $socket = Mockery::mock(WebSocketClient::class);
        $socket->shouldReceive('connect', 'close')->andReturnNull();
        $socket->shouldReceive('isConnected')->andReturnTrue();
        $socket->shouldReceive('send')->once()->andReturnUsing(function (array $frame) use (&$frames): void {
            $frames[] = $frame;
        });
        $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frames): array {
            $frame = end($frames);

            return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => [
                'vaultName' => 'orbit-control-inputs', 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption',
            ]]]];
        });
        app()->instance(WebSocketClient::class, $socket);
        $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
        $control = inventoryNode(1, '10.44.0.1');
        $control->roles()->create(['role' => 'swamp', 'status' => 'active']);
        $control->roles()->create(['role' => 'gateway', 'status' => 'active']);
        $target = inventoryNode($id, '10.44.0.3');
        $this->caller->accessibleNodes()->attach($target);
        $backing = [];
        if ($id === 17) {
            $process = ProcessModel::query()->create(['owner_type' => Node::class, 'owner_id' => $id, 'name' => 'primary-storage', 'runtime' => 'docker',
                'runtime_config' => ['image' => 'postgres:18', 'command' => ['postgres']], 'desired_state' => 'running', 'status' => 'active', 'keep_alive' => true]);
            DatabaseServer::query()->create(['node_id' => $id, 'process_id' => $process->id, 'slug' => 'primary-storage',
                'driver' => 'pgsql', 'tag' => '18', 'port' => 5432, 'status' => 'active', 'root_password' => 'fixture-password']);
            $backing[] = 'orbit-process-'.$process->id.'-primary-storage';
        }
        $headers = ['HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'rollout-inventory-'.$id];

        $response = $this->call('POST', "/api/v1/nodes/{$id}/base-inventory", server: $headers);
        expect($response->status())->toBe(202, $response->getContent());
        $response->assertJsonPath('data.target_id', $id);
        $operation = Operation::query()->sole();
        $this->call('POST', "/api/v1/nodes/{$id}/base-inventory", server: $headers)
            ->assertAccepted()->assertJsonPath('data.id', $operation->id);
        $verifier = hash('sha256', 'private-agent-fixture');
        $private = json_decode($frames[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR);
        expect($frames)->toHaveCount(1)
            ->and($frames[0]['type'])->toBe('vault.put')
            ->and($private['verifier'])->toBe($verifier)
            ->and($private['admission']['operation_id'])->toBe($operation->id)
            ->and($private['admission']['node_id'])->toBe($id)
            ->and($private['admission']['profile'])->toBe($profile)
            ->and($private['admission']['target']['node_id'])->toBe($id)
            ->and($private['admission']['target']['host_keys'])->toBe(['ssh-ed25519 pinned'])
            ->and($private['admission']['backing_containers'])->toBe($backing)
            ->and(json_encode($operation->input))->not->toContain($verifier, 'private-agent-fixture')
            ->and($response->getContent())->not->toContain($verifier, 'execution_secrets');
    })->with([[4, 'router'], [17, 'services'], [209, 'swamp']]);

    it('only accepts native operation results', function (): void {
        $operation = new Operation(['kind' => 'node.base_inventory']);
        expect(app(NodeBaseInventoryProjection::class)->native($operation))->toBeTrue();
    });
});

function inventoryReportFact(Node $node, string $kind, array $data, string $at): void
{
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->withToken('private-agent-fixture')
        ->postJson('/api/v1/agent/facts', ['kind' => $kind, 'data' => $data, 'observed_at' => $at])->assertNoContent();
}

function inventoryRoot(string $sampledAt, bool $stale = false): array
{
    return ['mount' => '/', 'size' => 100_000_000_000, 'free' => 90_000_000_000, 'observed_at' => $sampledAt, 'stale' => $stale];
}

function inventoryDoctorReceipt(Node $node, CarbonImmutable $clock): array
{
    $viewTime = (float) $clock->format('U.u');
    $view = test()->mock(AgentStateView::class);
    $view->shouldReceive('subscriber')->andReturn(new AgentViewSubscriberHealth($viewTime, true, true, 1));
    $view->shouldReceive('node')->andReturn(new AgentNodeView($node->id, AgentViewFreshness::Fresh, receivedAt: $viewTime));
    $view->shouldReceive('lastSeen')->with($node->id)->andReturn($viewTime);
    $run = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $operation = Operation::query()->create(['kind' => 'node.base_inventory', 'node_id' => $node->id,
        'target_type' => 'node', 'target_id' => $node->id, 'status' => 'running',
        'input' => ['profile' => 'services'], 'state' => ['swamp_run_id' => $run]]);

    return app(NodeBaseInventoryProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'succeeded',
        'started_at' => $clock->toISOString(), 'finished_at' => $clock->toISOString(),
        'result' => ['node_id' => $node->id, 'profile' => 'services', 'captured_at' => $clock->toISOString()]])->result['doctor'];
}

describe('rollout base inventory observer freshness', function (): void {
    beforeEach(function (): void {
        $this->node = inventoryNode(17, '10.44.0.3');
        $this->capture = CarbonImmutable::parse('2026-11-17T11:00:00Z');
        $this->travelTo($this->capture);
        $this->host = ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [inventoryRoot('2026-11-17T10:29:50Z')]];
        $this->units = ['docker' => 'available', 'units' => [], 'awake' => []];
        $this->ufw = ['installed' => true, 'enabled' => true, 'rules' => []];
    });

    it('confirms stable facts without changing their data or original observation and receipt times', function (): void {
        $this->travelTo($this->capture->subMinutes(30));
        foreach (['host', 'units', 'ufw'] as $kind) {
            inventoryReportFact($this->node, $kind, $this->{$kind}, '2026-11-17T10:30:00Z');
        }
        $original = NodeFact::query()->get()->keyBy(fn (NodeFact $fact): string => $fact->kind->value);
        $this->travelTo($this->capture);

        inventoryReportFact($this->node, 'host', array_replace($this->host, ['filesystems' => [inventoryRoot('2026-11-17T10:59:50Z')]]), '2026-11-17T10:59:59Z');
        inventoryReportFact($this->node, 'units', $this->units, '2026-11-17T10:59:59Z');
        inventoryReportFact($this->node, 'ufw', $this->ufw, '2026-11-17T10:59:59Z');
        $doctor = inventoryDoctorReceipt($this->node, $this->capture);

        foreach (NodeFact::query()->get() as $fact) {
            $before = $original[$fact->kind->value];
            expect($fact->only(['id', 'digest', 'data']))->toBe($before->only(['id', 'digest', 'data']));
            expect($fact->observed_at->equalTo($before->observed_at))->toBeTrue();
            expect($fact->received_at->equalTo($before->received_at))->toBeTrue();
        }
        expect($doctor['facts'])->toBe([
            'host' => ['observed_at' => '2026-11-17T10:30:00.000000Z', 'confirmed_at' => '2026-11-17T10:59:50.000000Z', 'fresh_for_capture' => true],
            'units' => ['observed_at' => '2026-11-17T10:30:00.000000Z', 'confirmed_at' => '2026-11-17T10:59:59.000000Z', 'fresh_for_capture' => true],
            'ufw' => ['observed_at' => '2026-11-17T10:30:00.000000Z', 'confirmed_at' => '2026-11-17T10:59:59.000000Z', 'fresh_for_capture' => true],
        ])->and($doctor['doctor_fresh_for_capture'])->toBeTrue();
    });

    it('orders reports by their sample time and ignores repeats and late changes', function (): void {
        inventoryReportFact($this->node, 'units', $this->units, '2026-11-17T10:30:00Z');
        inventoryReportFact($this->node, 'units', $this->units, '2026-11-17T10:40:00.900Z');
        $confirmed = NodeFact::query()->sole()->getAttributes();
        $changed = array_replace($this->units, ['docker' => 'absent']);

        inventoryReportFact($this->node, 'units', $changed, '2026-11-17T10:40:00.100Z');
        inventoryReportFact($this->node, 'units', $this->units, '2026-11-17T10:40:00.900Z');
        $doctor = inventoryDoctorReceipt($this->node, $this->capture);

        expect(NodeFact::query()->sole()->getAttributes())->toBe($confirmed);
        expect($doctor['facts']['units'])->toBe([
            'observed_at' => '2026-11-17T10:30:00.000000Z',
            'confirmed_at' => '2026-11-17T10:40:00.900000Z',
            'fresh_for_capture' => false,
        ])->and($doctor['agent_fresh_for_capture'])->toBeTrue()
            ->and($doctor['doctor_fresh_for_capture'])->toBeFalse();
    });

    it('keeps a changed units sample when the pinned agent reports twice in the same second', function (): void {
        inventoryReportFact($this->node, 'units', $this->units, '2026-11-17T10:59:59Z');

        inventoryReportFact($this->node, 'units', array_replace($this->units, ['docker' => 'absent']), '2026-11-17T10:59:59Z');

        $fact = NodeFact::query()->sole();
        expect($fact->data['docker'])->toBe('absent')
            ->and($fact->confirmed_at->toISOString())->toBe('2026-11-17T10:59:59.000000Z');
    });

    it('rejects future samples that could suppress later observations after clock correction', function (): void {
        inventoryReportFact($this->node, 'units', $this->units, '2026-11-17T10:59:50Z');

        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])->withToken('private-agent-fixture')
            ->postJson('/api/v1/agent/facts', ['kind' => 'units', 'data' => $this->units, 'observed_at' => '2026-11-17T12:00:00Z'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        inventoryReportFact($this->node, 'units', array_replace($this->units, ['docker' => 'absent']), '2026-11-17T11:00:00Z');

        $fact = NodeFact::query()->sole();
        expect($fact->data['docker'])->toBe('absent')
            ->and($fact->confirmed_at->toISOString())->toBe('2026-11-17T11:00:00.000000Z');
    });

    it('does not refresh the host without a current root sample', function (array $disks, ?string $confirmation): void {
        inventoryReportFact($this->node, 'host', $this->host, '2026-11-17T10:30:00Z');
        inventoryReportFact($this->node, 'units', $this->units, '2026-11-17T11:00:00Z');
        inventoryReportFact($this->node, 'ufw', $this->ufw, '2026-11-17T11:00:00Z');

        inventoryReportFact($this->node, 'host', array_replace($this->host, ['filesystems' => $disks]), '2026-11-17T11:00:00Z');
        inventoryReportFact($this->node, 'host', array_replace($this->host, ['filesystems' => [inventoryRoot('2026-11-17T10:58:00Z')]]), '2026-11-17T10:59:00Z');
        $doctor = inventoryDoctorReceipt($this->node, $this->capture);

        expect($doctor['facts']['host'])->toBe([
            'observed_at' => '2026-11-17T10:30:00.000000Z',
            'confirmed_at' => $confirmation,
            'fresh_for_capture' => false,
        ])->and($doctor['agent_fresh_for_capture'])->toBeTrue()
            ->and($doctor['doctor_fresh_for_capture'])->toBeFalse();
    })->with([
        'missing' => [[], null],
        'cached' => [[inventoryRoot('2026-11-17T10:29:50Z')], '2026-11-17T10:29:50.000000Z'],
        'stale' => [[inventoryRoot('2026-11-17T10:59:50Z', stale: true)], null],
        'after the host report' => [[inventoryRoot('2026-11-17T12:00:00Z')], null],
        'unattested' => [[['mount' => '/', 'size' => 100_000_000_000, 'free' => 90_000_000_000]], null],
    ]);
});
