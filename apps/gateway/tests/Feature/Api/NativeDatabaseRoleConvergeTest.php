<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Nodes\AssignRoleAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NativeNodeRoleConvergeInput;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind([new FakeFootprintArtifact('agent', str_repeat('a', 64))]);
    $certificates = Mockery::mock(LeafCertificateSigner::class);
    $certificates->shouldReceive('rootCertificate')->andReturn('public fixture certificate');
    app()->instance(LeafCertificateSigner::class, $certificates);
    FleetFixtures::node('gateway', [RoleName::Gateway]);
    $this->node = FleetFixtures::node('database', [RoleName::Database]);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp_workers.nodes' => []]);
    DB::beginTransaction();
});
afterEach(function (): void {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

it('admits Database-only convergence without a target worker and copies only the existing observer digest', function (): void {
    config(['swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database, true);
    expect(array_column($socket->sent, 'type'))->toBe(['vault.put'])
        ->and($socket->sent[0]['payload']['value'])->toBe(str_repeat('e', 64));
    $input = app(DirectOperation::class)->workflowInputs($operation);
    expect($input['intent']['node_id'])->toBe($this->node->id)
        ->and($input['intent']['ssh']['control_node_id'])->toBe(209)
        ->and($input['intent']['ssh']['target']['host_keys'])->toBe([$this->node->ssh_host_key_type.' '.$this->node->ssh_host_key])
        ->and($operation->input['participant_node_ids'])->toContain(209)
        ->and(NodeRoleDirectInput::ownerId($operation))->toBeNull()
        ->and(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('e', 64));
});

it('preserves the retained snapshot for other role sets and their callback reads', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    expect(NativeNodeRoleConvergeInput::supports($this->node, RoleName::Database))->toBeFalse();
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->node, $this->node->roles()->where('role', RoleName::Database)->sole(), false);
    $operation = new Operation(['kind' => 'node.role_converge', 'node_id' => $this->node->id, 'input' => ['_role' => $snapshot]]);
    expect(app(NodeRoleDirectProjection::class)->input($operation)['assignment_id'])->toBe($snapshot['assignment_id'])
        ->and(app(NodeRoleDirectProjection::class)->input($operation))->not->toHaveKey('intent');
});

it('does not admit an absent Database assignment as existing convergence', function (): void {
    $this->node->roles()->delete();
    expect(NativeNodeRoleConvergeInput::supports($this->node, RoleName::Database))->toBeFalse();
});

it('captures a real new assignment on a previously roleless Node without a Node worker', function (): void {
    $node = FleetFixtures::node('new-database');
    $assignment = app(AssignRoleAction::class)->execute($node, RoleName::Database);
    $snapshot = app(NodeRoleDirectInput::class)->capture($node, $assignment, true);

    $captured = app(NativeNodeRoleConvergeInput::class)->capture($node, $assignment, $snapshot);

    expect(NativeNodeRoleConvergeInput::supportsContribution($node, RoleName::Database))->toBeTrue()
        ->and($captured['intent'])->toMatchArray(['node_id' => $node->id, 'assignment_id' => $assignment->id,
            'role' => 'database', 'created' => true])
        ->and($captured['participant_node_ids'])->toContain(209, $node->id)
        ->and($assignment->refresh()->status->value)->toBe('provisioning');
});

it('captures a supported role on a mixed Node while keeping standalone selection narrow', function (): void {
    $assignment = $this->node->roles()->sole();
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->node, $assignment, false);

    $captured = app(NativeNodeRoleConvergeInput::class)->capture($this->node, $assignment, $snapshot);

    expect(NativeNodeRoleConvergeInput::supports($this->node, RoleName::Database))->toBeFalse()
        ->and(NativeNodeRoleConvergeInput::supportsContribution($this->node, RoleName::Database))->toBeTrue()
        ->and($captured['intent']['created'])->toBeFalse()
        ->and($this->node->roles()->count())->toBe(2);
});

it('refuses forged or stale assignment and snapshot identity before returning native intent', function (): void {
    $assignment = $this->node->roles()->sole();
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->node, $assignment, false);
    $capture = app(NativeNodeRoleConvergeInput::class);
    foreach ([['node_id' => $this->node->id + 1], ['assignment_id' => $assignment->id + 1],
        ['role' => 'ingress'], ['created' => 'true']] as $change) {
        expect(fn () => $capture->capture($this->node, $assignment, [...$snapshot, ...$change]))
            ->toThrow(ResourceOperationException::class);
    }
    $forged = new NodeRole($assignment->getAttributes());
    $forged->setAttribute('id', $assignment->id);
    expect(fn () => $capture->capture($this->node, $forged, $snapshot))->toThrow(ResourceOperationException::class);
    $assignment->delete();
    $assignment->exists = true;
    expect(fn () => $capture->capture($this->node, $assignment, $snapshot))->toThrow(ResourceOperationException::class);
});

it('captures the real transferred WebSocket assignment and target private material on a mixed Node', function (): void {
    $source = FleetFixtures::node('prior-websocket', [RoleName::WebSocket]);
    $target = FleetFixtures::node('target-websocket', [RoleName::AppDev]);
    $vpn = FleetFixtures::node('publication-dns', [RoleName::Vpn]);
    $assignment = $source->roles()->sole();
    app(CaddySiteCertificates::class)->record($source->id, 'websocket');
    app(WebSocketDnsTarget::class)->markServing($source->id);
    $assignment->update(['node_id' => $target->id, 'status' => 'provisioning']);
    app(NodeRoleRuntimeInput::class)->prepare($target, $assignment);
    $snapshot = app(NodeRoleDirectInput::class)->capture($target, $assignment, false);

    $captured = app(NativeNodeRoleConvergeInput::class)->capture($target, $assignment, $snapshot);
    $private = app(NodeRoleRuntimeSource::class)->resolveRoleMaterial($snapshot, $target->id);
    $dns = array_find($captured['intent']['publications'], static fn (array $row): bool => $row['node_id'] === $vpn->id);

    expect($captured['intent'])->toMatchArray(['node_id' => $target->id, 'assignment_id' => $assignment->id,
        'role' => 'websocket', 'created' => false])
        ->and($captured['intent']['certificates']['leaves'][0]['node_id'])->toBe($target->id)
        ->and($private->value)->toHaveKey('websocket_environment')
        ->and(json_encode($captured, JSON_THROW_ON_ERROR))->not->toContain('REGISTRY_TOKEN=', 'APP_KEY=')
        ->and($assignment->refresh()->node_id)->toBe($target->id)
        ->and($dns['before']['dns']['records'])->toContain('host-record=reverb.orbit,'.$source->wireguard_ip)
        ->and($dns['after']['dns']['records'])->toContain('host-record=reverb.orbit,'.$target->wireguard_ip)
        ->and($dns['after']['dns']['records'])->not->toContain('host-record=reverb.orbit,'.$source->wireguard_ip)
        ->and(app(WebSocketDnsTarget::class)->isServing($source->id))->toBeTrue()
        ->and(app(WebSocketDnsTarget::class)->isServing($target->id))->toBeFalse();
});

it('keeps the observer configuration free of worker credentials when its Node is selected for rollout', function (): void {
    config(['swamp_workers.nodes' => [$this->node->id]]);
    $configuration = app(NodeAgentSshExecutor::class)->configurationForRoot($this->node, null)['configuration'];
    expect($configuration)->not->toContain('[swamp_worker]', 'worker_token', 'enrollment_token');
});

it('refuses a Metrics fleet rather than omitting its convergence', function (): void {
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->node, $this->node->roles->sole(), false);
    $snapshot['metrics_fleet'] = ['metrics_node_id' => 10];
    expect(fn () => app(NativeNodeRoleConvergeInput::class)->capture($this->node, $this->node->roles->sole(), $snapshot))
        ->toThrow(ResourceOperationException::class);
});

it('refuses an unimplemented required footprint contribution', function (): void {
    $snapshot = app(NodeRoleDirectInput::class)->capture($this->node, $this->node->roles->sole(), false);
    $snapshot['footprint']['artifacts'][] = ['name' => 'caddy', 'digest' => str_repeat('a', 64), 'required' => true, 'repair' => false];
    expect(fn () => app(NativeNodeRoleConvergeInput::class)->capture($this->node, $this->node->roles->sole(), $snapshot))
        ->toThrow(ResourceOperationException::class);
});

function databaseNativeBound(Node $node): Operation
{
    $assignment = $node->roles()->where('role', RoleName::Database)->sole();
    $snapshot = app(NodeRoleDirectInput::class)->capture($node, $assignment, false);
    $native = app(NativeNodeRoleConvergeInput::class)->capture($node, $assignment, $snapshot);
    $assignment->update(['status' => 'provisioning']);

    return Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'sequence' => 1, 'attempts' => 0,
        'input' => ['_role' => $snapshot, 'native_role' => $native['intent']],
        'state' => ['node_role_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
}

it('rejects a retained HTTP terminal callback for a native Database admission', function (): void {
    $operation = databaseNativeBound($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])
        ->postJson('/api/v1/direct-operations/'.$operation->id.'/result', [
            'run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
            'started_at' => '2026-10-31T03:00:00.000Z', 'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => [],
        ])->assertForbidden();
    expect($operation->refresh()->status->value)->toBe('running')
        ->and($this->node->roles()->sole()->status->value)->toBe('provisioning');
});

it('projects the native stdout receipt through the actual shared terminal subscriber boundary', function (): void {
    $operation = databaseNativeBound($this->node);
    $result = ['role_proven' => true, 'assignment_id' => $this->node->roles()->sole()->id, 'created' => false,
        'footprint' => ['footprint_proven' => true, 'artifacts' => [['name' => 'agent', 'status' => 'applied']]],
        'cli' => ['outcome' => 'skipped', 'reason' => 'release_unavailable'], 'follow_up' => null];
    $run = ['id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-node-role-converge-209',
        'status' => 'succeeded', 'inputs' => app(DirectOperation::class)->workflowInputs($operation),
        'startedAt' => '2026-10-31T03:00:00.000Z', 'completedAt' => '2026-10-31T03:00:01.000Z',
        'jobs' => [['name' => 'result', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]]]]]];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $run]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.read_token' => 'fake.read']);
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $run['id'], 'status' => 'succeeded'], ['job' => 'result', 'step' => 'result']);
    expect($operation->refresh()->result)->toBe($result)
        ->and($this->node->roles()->sole()->status->value)->toBe('active');
});

it('sends bound cancellation to the exact native run and leaves terminal state to Swamp', function (): void {
    $operation = databaseNativeBound($this->node);
    config(['swamp.run_token' => 'fake.cancel']);
    Http::fake(['https://swamp.orbit/api/v1/cancel/workflow-run/*' => Http::response(['status' => 'cancelled'])]);
    app(CancelOperationAction::class)->execute($operation);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://swamp.orbit/api/v1/cancel/workflow-run/'.$operation->state['swamp_run_id']
        && $request->hasHeader('Authorization', 'Bearer fake.cancel'));
    expect($operation->refresh()->cancel_requested_at)->not->toBeNull()->and($operation->status->value)->toBe('running');
});

it('reports an unconfirmed native cancellation without inventing terminal success', function (): void {
    $operation = databaseNativeBound($this->node);
    config(['swamp.run_token' => 'fake.cancel']);
    Http::fake(['https://swamp.orbit/api/v1/cancel/workflow-run/*' => Http::response([], 503)]);
    expect(fn () => app(CancelOperationAction::class)->execute($operation))->toThrow(ResourceOperationException::class);
    expect($operation->refresh()->cancel_requested_at)->not->toBeNull()->and($operation->status->value)->toBe('running');
});

it('captures complete pinned Ingress publication and firewall without a target worker', function (): void {
    FleetFixtures::bind([new FakeFootprintArtifact('agent', str_repeat('a', 64)), new FakeFootprintArtifact('caddy', str_repeat('b', 64))]);
    $node = FleetFixtures::node('ingress');
    $cluster = Cluster::query()->create(['name' => 'native-ingress']);
    $node->update(['cluster_id' => $cluster->id]);
    $node->roles()->create(['role' => RoleName::Ingress, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::Ingress, true);
    $intent = app(DirectOperation::class)->workflowInputs($operation)['intent'];
    expect($intent['role'])->toBe('ingress')
        ->and($intent['caddy']['connection']['nodeHost'])->toBe($node->wireguard_ip)
        ->and($intent['caddy']['host_keys'])->toBe([$node->ssh_host_key_type.' '.$node->ssh_host_key])
        ->and($intent['firewall']['desired'])->not->toBeEmpty()
        ->and($intent['firewall']['desired'][0])->toBe(['ufw', 'allow', 'in', 'on', 'orbit', 'to', $node->wireguard_ip, 'comment', 'orbit:wireguard-members'])
        ->and($intent['firewall']['remove'][0])->toBe(['ufw', '--force', 'delete', 'allow', 'in', 'proto', 'tcp', 'to', 'any', 'port', (string) $node->public_ssh_port, 'comment', 'orbit:public-ssh-recovery'])
        ->and($intent['caddy_package'])->toHaveKeys(['release', 'minimum_version', 'amd64_sha512']);
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    expect(NativeNodeRoleConvergeInput::supports($node, RoleName::Ingress))->toBeFalse();
});

it('admits existing WebSocket convergence with public publications and private existing runtime material', function (): void {
    config(['swamp.run_token' => 'fake.token']);
    $node = FleetFixtures::node('reverb-native', [RoleName::WebSocket]);
    $node->forceFill(['agent_secret_hash' => str_repeat('d', 64)])->save();
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::WebSocket, true);
    $input = app(DirectOperation::class)->workflowInputs($operation);
    expect($input['intent']['role'])->toBe('websocket')
        ->and($input['intent']['certificates']['leaves'][0]['scope'])->toBe('role-websocket')
        ->and($input['intent']['publications'])->not->toBeEmpty()
        ->and(array_keys($input['execution_secrets']))->toBe(['observer', 'runtime_material'])
        ->and(count($socket->sent))->toBe(2)
        ->and(json_decode($socket->sent[1]['payload']['value'], true, 512, JSON_THROW_ON_ERROR))->toHaveKey('websocket_environment')
        ->and(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('d', 64), 'REGISTRY_TOKEN=', 'APP_KEY=')
        ->and(NodeRoleDirectInput::ownerId($operation))->toBeNull();
    expect(app(WebSocketDnsTarget::class)->isServing($node->id))->toBeFalse();
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'native-websocket-run', 'swamp_workflow' => 'operation-node-role-converge-209', 'node_role_writer' => 'direct']])->save();
    app(NodeRoleDirectProjection::class)->apply($operation, [
        'run_id' => 'native-websocket-run', 'status' => 'succeeded', 'started_at' => '2026-11-01T06:00:00Z', 'finished_at' => '2026-11-01T06:00:01Z',
        'result' => ['assignment_id' => $input['intent']['assignment_id'], 'created' => false, 'role_proven' => true,
            'certificates_proven' => true, 'runtime_proven' => true, 'publication_proven' => true,
            'footprint' => ['footprint_proven' => true, 'artifacts' => [['name' => 'agent', 'status' => 'applied']]],
            'cli' => ['outcome' => 'skipped', 'reason' => 'release_unavailable'], 'follow_up' => null],
    ]);
    expect(app(WebSocketDnsTarget::class)->isServing($node->id))->toBeTrue()
        ->and(app(CaddySiteCertificates::class)->published($node->id, 'websocket'))->toBeTrue();
});

it('refuses unsupported WebSocket convergence before claims or private copies even when its old worker is selected', function (): void {
    $node = FleetFixtures::node('reverb-mixed', [RoleName::WebSocket, RoleName::AppDev]);
    $node->forceFill(['agent_secret_hash' => str_repeat('d', 64)])->save();
    config(['swamp_workers.nodes' => [$node->id], 'swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $before = $node->roles()->pluck('status', 'id')->all();
    $operations = Operation::query()->count();

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::WebSocket, true))
        ->toThrow(ResourceOperationException::class, 'WebSocket convergence requires a supported native role plan.');

    expect(Operation::query()->count())->toBe($operations)
        ->and($node->roles()->pluck('status', 'id')->all())->toBe($before)
        ->and($socket->sent)->toBeEmpty();
});
