<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmEnrollmentWorkflowInput;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\TaskVm;
use Illuminate\Support\Arr;
use Tests\Support\FakeRoutePublicationSocket;

/** @return array{array<string,mixed>,array<string,mixed>} */
function commandEnrollmentCapture(): array
{
    return [[
        'task_vm_id' => 184, 'guest_node_id' => 212, 'execution_node_id' => 13,
        'provider' => 'incus', 'name' => 'tvm-184', 'user' => 'orbit',
        'agent_sha256' => str_repeat('a', 64), 'agent_artifact_url' => 'https://example.test/observer',
        'pi_url' => 'http://10.44.0.212:7331', 'vm' => ['project' => 'orbit-tasks', 'name' => 'tvm-184'],
        'node' => ['node_id' => 212, 'name' => 'tvm-184', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.212'],
        'bootstrap' => ['user' => 'orbit', 'public_key' => 'ssh-ed25519 PUBLIC', 'releases' => ['resolute'], 'packages' => ['curl'], 'dns_policy' => 'preserve'],
        'agent' => ['configuration' => 'public-config', 'certificate' => 'PUBLIC CA', 'unit' => 'public-unit', 'checkouts' => []],
        'runtime' => ['guest_node_id' => 212, 'user' => 'orbit', 'agent_sha256' => str_repeat('a', 64),
            'pi_sha256' => str_repeat('b', 64), 'origin' => 'https://models.example.test', 'models' => ['one'],
            'process' => ['process_id' => 55, 'name' => 'orbit-process-55-pi-server.service', 'runtime' => 'systemd', 'content_hash' => str_repeat('c', 64), 'desired' => 'running', 'on_demand' => false, 'activation_pending' => true]],
    ], [
        'gateway_node_id' => 14, 'issuer_node_id' => 209, 'model_router_node_id' => 3,
        'model_origin' => 'https://models.example.test', 'participant_node_ids' => [13, 14, 209, 3, 212, 1],
        '_wireguard' => ['schema_version' => 1, 'executor_node_id' => 14, 'hub_id' => 1, 'context_node_id' => 212, 'excluded_node_id' => null, 'agent_sha256' => str_repeat('a', 64)],
        'network' => ['server_public_key' => 'PUBLIC WG', 'endpoint' => 'vpn.example.test:51820', 'address' => '10.44.0.212/32', 'subnet' => '10.44.0.0/16', 'dns_server' => '10.44.0.1', 'domains' => ['orbit']],
    ]];
}

it('supplies the complete public intent and drops existing private transport fields', function (): void {
    [$intent, $placement] = commandEnrollmentCapture();
    $intent['model_key'] = 'private-model-key';
    Arr::set($intent, 'runtime.pi_token', 'private-pi-token');
    Arr::set($intent, 'agent.worker_credentials', 'private-worker-pair');
    $placement['pi_artifact'] = ['path' => '/private/artifact'];
    $placement['private_bindings'] = ['configuration' => ['owner_node_id' => 1]];
    $placement['configuration'] = ['$secret' => 'node.vpn_configuration', 'id' => 1];

    $public = app(TaskVmEnrollmentWorkflowInput::class)->fromCaptured($intent, $placement, ['address' => null, 'public_key' => null]);

    expect($public)->toMatchArray(['schema_version' => 1, 'task_vm_id' => 184, 'guest_node_id' => 212, 'execution_node_id' => 13, 'gateway_node_id' => 14, 'issuer_node_id' => 209, 'model_router_node_id' => 3]);
    expect(data_get($public, 'bootstrap.public_key'))->toBe('ssh-ed25519 PUBLIC');
    expect(data_get($public, 'runtime.process.process_id'))->toBe(55);
    expect($public['connection'])->toBe(['address' => null, 'public_key' => null]);
    expect(json_encode($public, JSON_THROW_ON_ERROR))->not->toContain('private-model-key', 'private-pi-token', 'private-worker-pair', '/private/artifact', '$secret', 'private_bindings');
});

it('refuses mismatched public target or effect placement', function (string $field): void {
    [$intent, $placement] = commandEnrollmentCapture();
    match ($field) {
        'name' => $intent['name'] = 'tvm-185',
        'guest' => Arr::set($intent, 'node.node_id', 213),
        'runtime' => Arr::set($intent, 'runtime.guest_node_id', 213),
        'origin' => $placement['model_origin'] = 'https://foreign.example.test',
        'hub-executor' => Arr::set($placement, '_wireguard.executor_node_id', 13),
        'hub-context' => Arr::set($placement, '_wireguard.context_node_id', 213),
        'hub-exclusion' => Arr::set($placement, '_wireguard.excluded_node_id', 212),
        'executor' => $intent['execution_node_id'] = 212,
        'provider' => $intent['provider'] = 'unknown',
        'participants' => $placement['participant_node_ids'] = [13, 14, 209, 3, 212],
        'issuer' => $placement['issuer_node_id'] = 0,
        default => throw new LogicException('Unknown mutation'),
    };
    expect(fn () => app(TaskVmEnrollmentWorkflowInput::class)->fromCaptured($intent, $placement, ['address' => null, 'public_key' => null]))
        ->toThrow(ResourceOperationException::class);
})->with(['name', 'guest', 'runtime', 'origin', 'hub-executor', 'hub-context', 'hub-exclusion', 'executor', 'provider', 'participants', 'issuer']);

it('keeps UpCloud execution on the admitted Gateway and carries only its public pin', function (): void {
    [$intent, $placement] = commandEnrollmentCapture();
    $intent['provider'] = 'upcloud';
    $intent['vm'] = null;
    $intent['execution_node_id'] = 14;
    $connection = ['address' => '192.0.2.184', 'public_key' => 'ssh-ed25519 PIN'];
    $public = app(TaskVmEnrollmentWorkflowInput::class)->fromCaptured($intent, $placement, $connection);
    expect($public['connection'])->toBe($connection)->and($public['vm'])->toBeNull();
    $intent['execution_node_id'] = 13;
    expect(fn () => app(TaskVmEnrollmentWorkflowInput::class)->fromCaptured($intent, $placement, $connection))->toThrow(ResourceOperationException::class);
});

it('composes canonical model descriptors and Pi copy through the shared helper only', function (): void {
    config(['swamp.address' => '192.0.2.209', 'swamp.run_token' => 'synthetic-run.private', 'swamp.ca_path' => '/public/root.pem']);
    $issuer = Mockery::mock(ModelKeyIssuer::class);
    $issuer->shouldReceive('origin')->andReturn('https://models.example.test');
    $issuer->shouldReceive('ownerNodeId')->andReturn(3);
    $issuer->shouldReceive('privateMaterial')->once()->with('https://models.example.test', 3)
        ->andReturn(['management_key' => 'synthetic-management', 'anchor_key' => str_repeat('2', 64)]);
    app()->instance(ModelKeyIssuer::class, $issuer);
    $writes = [];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static function (array $request) use (&$writes): array {
        $writes[] = $request['payload']['key'];

        return [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
            'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption',
        ]]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    $vm = (new TaskVm)->forceFill(['id' => 184, 'model_key' => str_repeat('1', 64),
        'pi_token' => str_repeat('3', 64), 'ai_router_origin' => 'https://models.example.test']);
    [$intent, $placement] = commandEnrollmentCapture();
    $supplier = app(TaskVmEnrollmentWorkflowInput::class);
    $public = $supplier->fromCaptured($intent, $placement, ['address' => null, 'public_key' => null]);
    $captured = $supplier->withExecutionCopies($vm, $public);
    $envelope = $supplier->envelope($captured);

    expect($writes)->toBe(['task-vm-model-key-184', 'model-router-management-node-3', 'model-router-anchor-node-3', 'task-vm-pi-token-184']);
    expect($socket->sent)->toHaveCount(4);
    expect($envelope['intent'])->toBe($public);
    expect(array_keys($envelope['execution_secrets']))->toBe(['model_key', 'model_router_management', 'model_router_anchor', 'pi_token']);
    expect(Arr::only($envelope['execution_secrets'], ['model_key', 'model_router_management', 'model_router_anchor']))
        ->toBe($captured['model_execution_secrets']);
    expect(json_encode($captured, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('1', 64), str_repeat('2', 64), str_repeat('3', 64), 'synthetic-management');
});
