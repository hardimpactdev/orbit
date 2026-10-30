<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeNetworkOperationExecution;
use App\Domain\Nodes\PrepareNodeNetworkCommand;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\BootstrapSshOperationTransport;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\Fleet\FleetFixtures;

function networkProofOperation(): Operation
{
    $hub = FleetFixtures::node('network-hub', [RoleName::Vpn]);
    $peer = FleetFixtures::node('network-peer');
    $key = base64_encode(str_repeat('a', 32));
    $peer->update(['wireguard_public_key' => $key]);
    $intent = ['key' => 'network-hub', 'kind' => 'node.wireguard_hub_converge', 'node_id' => $hub->id,
        'input' => ['context_node_id' => $peer->id, 'excluded_node_id' => null]];

    return Operation::query()->create(['kind' => 'node.network_converge', 'target_type' => 'node', 'target_id' => $peer->id,
        'node_id' => $peer->id, 'input' => ['roleless_operator' => false, 'agent_pin' => str_repeat('a', 64),
            'bootstrap_host' => $peer->public_ssh_host, 'bootstrap_port' => 22, 'bootstrap_user' => 'orbit',
            'bootstrap_fingerprint' => 'SHA256:'.str_repeat('A', 43),
            'bootstrap_identity' => ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $peer->id],
            '_network' => ['hub_id' => $hub->id, 'existing_key' => $key, 'architecture' => 'x86_64',
                'peer' => ['retain' => true], 'trust' => ['desired' => [], 'remove' => [], 'enable' => false]]],
        'state' => ['node_network_prepared' => true, 'child_intents' => [$intent]], 'status' => 'running', 'sequence' => 1, 'attempts' => 1]);
}

function networkProofRun(Operation $operation, string $key, array $result): void
{
    $descriptor = array_find(app(NodeNetworkOperationExecution::class)->context($operation)['descriptors'], static fn (array $entry): bool => $entry['key'] === $key);
    expect($descriptor)->not->toBeNull();
    $runs = $operation->state['executor_runs'] ?? [];
    $runs[$key] = [...$descriptor, 'result' => $result];
    $state = [...$operation->state, 'executor_runs' => $runs];
    if ($key === 'network-key' && isset($result['public_key'])) {
        $state['network_public_key'] = $result['public_key'];
    }
    $operation->update(['state' => $state]);
}

function networkProofHub(Operation $operation, string $status = 'succeeded'): Operation
{
    $intent = $operation->state['child_intents'][0];
    $identity = ['parent_operation_id' => $operation->id, 'key' => $intent['key'], 'kind' => $intent['kind'], 'node_id' => $intent['node_id'],
        'target_type' => 'node', 'target_id' => $intent['node_id'], 'input' => $intent['input'], 'run_constraints' => []];
    $snapshot = ['intent' => $intent, 'admitted' => $identity];
    $child = new Operation;
    $child->forceFill(['kind' => $intent['kind'], 'node_id' => $intent['node_id'], 'target_type' => 'node', 'target_id' => $intent['node_id'],
        'input' => $intent['input'], 'parent_operation_id' => $operation->id, 'child_key' => $intent['key'], 'child_admission' => $snapshot,
        'child_fingerprint' => OperationChildIntent::fingerprint($snapshot), 'run_constraints' => [],
        'status' => $status, 'error_code' => $status === 'failed' ? 'vpn.server_start_failed' : null, 'result' => ['projected' => true], 'sequence' => 1, 'attempts' => 1])->save();

    return $child;
}

it('admits the hub after the peer key with an explicit null exclusion', function (): void {
    $operation = networkProofOperation();
    $execution = app(NodeNetworkOperationExecution::class);
    expect($execution->childContext($operation)['children'])->toBe([]);
    networkProofRun($operation, 'network-key', ['public_key' => $operation->input['_network']['existing_key']]);

    expect($execution->childContext($operation)['children'])->toBe($operation->state['child_intents']);
    expect(array_column($execution->context($operation)['descriptors'], 'key'))->toBe(['network-prepare', 'network-key']);
    expect($operation->state['child_intents'][0]['input'])->toBe(['context_node_id' => $operation->node_id, 'excluded_node_id' => null]);
});

it('refuses caller steps as proof of the network transition', function (): void {
    $operation = networkProofOperation();
    $operation->update(['step_results' => ['network-key' => ['public_key' => $operation->input['_network']['existing_key']]]]);

    expect(fn () => app(NodeNetworkOperationExecution::class)->result($operation))->toThrow(ResourceOperationException::class);
});

it('requires private SSH and firewall proof after the stamped hub child', function (): void {
    $operation = networkProofOperation();
    networkProofRun($operation, 'network-key', ['public_key' => $operation->input['_network']['existing_key']]);
    networkProofHub($operation);
    networkProofRun($operation, 'network-publish', ['public_key' => $operation->input['_network']['existing_key']]);
    networkProofRun($operation, 'network-private-ssh', ['architecture' => 'x86_64']);
    $execution = app(NodeNetworkOperationExecution::class);
    expect(fn () => $execution->result($operation))->toThrow(ResourceOperationException::class);
    networkProofRun($operation, 'network-trust', ['matches' => true]);

    expect($execution->result($operation))->toBe(['network_proven' => true, 'public_key' => $operation->input['_network']['existing_key'], 'retained' => true]);
});

it('requires rollback proof before reporting a retained peer failure', function (bool $rollbackFails): void {
    $operation = networkProofOperation();
    networkProofRun($operation, 'network-key', ['public_key' => $operation->input['_network']['existing_key']]);
    networkProofHub($operation);
    networkProofRun($operation, 'network-publish', ['public_key' => $operation->input['_network']['existing_key']]);
    networkProofRun($operation, 'network-private-ssh', ['error_code' => 'vpn.peer_ssh_failed']);
    $execution = app(NodeNetworkOperationExecution::class);
    expect(fn () => $execution->result($operation))->toThrow(ResourceOperationException::class);
    networkProofRun($operation, 'network-rollback', $rollbackFails ? ['error_code' => 'wireguard.peer_rollback_failed'] : []);

    expect($execution->result($operation))->toBe(['network_proven' => true,
        'error_code' => $rollbackFails ? 'vpn.peer_rollback_failed' : 'vpn.peer_ssh_failed',
        'failed_step' => $rollbackFails ? 'network-rollback' : 'network-private-ssh']);
})->with([false, true]);

it('does not publish the peer when the hub refuses its projection', function (): void {
    $operation = networkProofOperation();
    networkProofRun($operation, 'network-key', ['public_key' => $operation->input['_network']['existing_key']]);
    networkProofHub($operation, 'failed');

    expect(array_column(app(NodeNetworkOperationExecution::class)->context($operation)['descriptors'], 'key'))->toBe(['network-prepare', 'network-key', 'network-result']);
});

it('refuses a successful hub child whose stamped input changed', function (): void {
    $operation = networkProofOperation();
    networkProofRun($operation, 'network-key', ['public_key' => $operation->input['_network']['existing_key']]);
    networkProofHub($operation)->update(['input' => ['context_node_id' => $operation->node_id + 1, 'excluded_node_id' => null]]);

    expect(fn () => app(NodeNetworkOperationExecution::class)->context($operation))->toThrow(ResourceOperationException::class);
});

it('refuses native results that disagree with the admitted key or architecture', function (): void {
    $operation = networkProofOperation();
    $execution = app(NodeNetworkOperationExecution::class);

    expect($execution->received($operation, ['key' => 'network-key'], ['public_key' => base64_encode(str_repeat('b', 32))]))->toBe(['error_code' => 'vpn.peer_key_mismatch']);
    expect($execution->received($operation, ['key' => 'network-private-ssh'], ['architecture' => 'aarch64']))->toBe(['error_code' => 'node.architecture_mismatch']);
});

it('captures public configuration without reading a private key and reuses that snapshot', function (): void {
    $operation = networkProofOperation();
    $home = sys_get_temp_dir().'/node-network-'.bin2hex(random_bytes(6));
    mkdir($home.'/wireguard', 0700, true);
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('s', 32)));
    config(['orbit.home' => $home]);
    $operation->update(['state' => []]);
    try {
        $command = app(PrepareNodeNetworkCommand::class);
        expect($command->run($operation, ['roleless_operator' => false]))->toBe(['prepared' => true]);
        $snapshot = $operation->fresh()->input['_network'];
        expect($snapshot['peer'])->toMatchArray(['server_public_key' => base64_encode(str_repeat('s', 32)), 'operator_dns' => null, 'retain' => true]);
        expect($snapshot)->not->toHaveKey('server_private_key');
        expect($operation->state['child_intents'][0]['input'])->toBe(['context_node_id' => $operation->node_id, 'excluded_node_id' => null]);
        file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('z', 32)));

        $command->run($operation, ['roleless_operator' => false]);

        expect($operation->fresh()->input['_network'])->toBe($snapshot);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('uses native peer-key proof for the bound hub child through the executor API', function (): void {
    $operation = networkProofOperation();
    $operation->update(['status' => 'queued']);
    $peer = Node::query()->findOrFail($operation->node_id);
    $peer->update(['ssh_host_fingerprint' => $operation->input['bootstrap_fingerprint']]);
    $gateway = FleetFixtures::node('network-gateway', [RoleName::Gateway]);
    $services = FleetFixtures::node('network-services');
    $services->accessibleNodes()->attach($gateway);
    $home = sys_get_temp_dir().'/node-network-http-'.bin2hex(random_bytes(6));
    config(['dependency_detection.services_node_id' => $services->id, 'orbit.home' => $home]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.network_converge', 'worker' => 'network-worker'])->assertOk();
    $operation->refresh();
    $descriptor = app(NodeNetworkOperationExecution::class)->context($operation)['descriptors'][1];
    $scanner = Mockery::mock(HostKeyScanner::class);
    $hostKey = new HostKey('ssh-ed25519', 'test-public-key', $operation->input['bootstrap_fingerprint']);
    $scanner->shouldReceive('scan')->once()->with($operation->input['bootstrap_host'], 22, null)->andReturn($hostKey);
    app()->instance(HostKeyScanner::class, $scanner);
    $known = Mockery::mock(KnownHostsStore::class);
    $known->shouldReceive('put')->once()->with($operation->input['bootstrap_host'], 22, $hostKey);
    $known->shouldReceive('put')->once()->with($peer->wireguard_ip, 22, $hostKey);
    $known->shouldReceive('path')->andReturn('/fixture/known-hosts');
    app()->instance(KnownHostsStore::class, $known);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->once()->andReturn('/fixture/network-credential');
    app()->instance(SshKeyProvider::class, $keys);
    $runner = Mockery::mock(AdmissionAgentRunner::class);
    $runner->shouldReceive('runOver')->once()->withArgs(function ($node, $connection, $name, $input) use ($operation): bool {
        expect($input['existing'])->toBeTrue();
        expect($connection->host)->toBe($operation->input['bootstrap_host']);

        return $node->id === $operation->node_id && $name === 'wireguard.prepare-peer-key';
    })->andReturn(['public_key' => $operation->input['_network']['existing_key'], 'debug' => '/fixture/network-credential']);
    app()->instance(AdmissionAgentRunner::class, $runner);
    $body = ['worker' => 'network-worker', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']];
    try {
        $changed = $body;
        $changed['input']['bootstrap_host'] = '192.0.2.99';
        $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $changed)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
        $this->postJson("/api/v1/operation-executor/{$operation->id}/children", ['worker' => 'network-worker', 'key' => 'network-hub'])->assertUnprocessable();
        $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)->assertOk()->assertJsonPath('data.public_key', $operation->input['_network']['existing_key'])->assertDontSee('/fixture/network-credential');
        $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)->assertOk();
        $this->postJson("/api/v1/operation-executor/{$operation->id}/children", ['worker' => 'network-worker', 'key' => 'network-hub'])->assertAccepted();
        $child = $operation->children()->sole();
        app(OperationChildren::class)->verify($operation->fresh(), $child);
        expect($child->input['_wireguard']['excluded_node_id'])->toBeNull();
        expect($child->node_id)->toBe($operation->input['_network']['hub_id']);
        expect(json_encode($operation->fresh()->state))->not->toContain('/fixture/network-credential');
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('retries the private status read after a lost transport response without replaying publication', function (): void {
    $operation = networkProofOperation();
    networkProofRun($operation, 'network-key', ['public_key' => $operation->input['_network']['existing_key']]);
    networkProofHub($operation);
    networkProofRun($operation, 'network-publish', ['public_key' => $operation->input['_network']['existing_key']]);
    $operation->update(['status' => 'queued']);
    $gateway = FleetFixtures::node('retry-gateway', [RoleName::Gateway]);
    $services = FleetFixtures::node('retry-services');
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $calls = 0;
    $runner->shouldReceive('run')->twice()->andReturnUsing(function ($node, $name, $input) use (&$calls, $operation): array {
        expect($node->id)->toBe($operation->node_id)->and($name)->toBe('agent.status')->and($input)->toBe([]);
        if (++$calls === 1) {
            throw new RuntimeException('Lost read response.');
        }

        return ['architecture' => 'x86_64'];
    });
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.network_converge', 'worker' => 'read-retry'])->assertOk();
    $descriptor = array_find(app(NodeNetworkOperationExecution::class)->context($operation->refresh())['descriptors'], fn ($d) => $d['key'] === 'network-private-ssh');
    $body = ['worker' => 'read-retry', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => (object) $descriptor['input']];
    $path = '/api/v1/operation-executor/'.$operation->id.'/run';
    $this->postJson($path, $body)->assertStatus(502)->assertJsonPath('error.code', 'operation.execution_failed');
    $this->postJson($path, $body)->assertOk()->assertJsonPath('data.architecture', 'x86_64');
    $this->postJson($path, $body)->assertOk()->assertJsonPath('data.architecture', 'x86_64');
    expect($operation->fresh()->state['executor_runs']['network-publish']['result']['public_key'])->toBe($operation->input['_network']['existing_key']);
});

it('refuses an unverified bootstrap key before changing the WireGuard SSH pin', function (): void {
    $operation = networkProofOperation();
    $descriptor = app(NodeNetworkOperationExecution::class)->context($operation)['descriptors'][1];
    $scanner = Mockery::mock(HostKeyScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn(new HostKey('ssh-ed25519', 'foreign-public-key', 'SHA256:'.str_repeat('B', 43)));
    app()->instance(HostKeyScanner::class, $scanner);
    $known = Mockery::mock(KnownHostsStore::class);
    $known->shouldNotReceive('put');
    app()->instance(KnownHostsStore::class, $known);
    $runner = Mockery::mock(AdmissionAgentRunner::class);
    $runner->shouldNotReceive('runOver');
    app()->instance(AdmissionAgentRunner::class, $runner);
    $input = [...$descriptor['input'], 'bootstrap_identity' => '/fixture/identity'];
    expect(fn () => app(BootstrapSshOperationTransport::class)->run($operation, $descriptor, $input))->toThrow(ResourceOperationException::class);
});
