<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeBootstrapOperationCompletion;
use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Nodes\NodeWireGuardOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\Nodes\NodeOperationAgentInstaller;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

function bootstrapProbeOperation($test, string $platform = 'linux', ?string $expected = null): array
{
    $gateway = FleetFixtures::node('probe-gateway', [RoleName::Gateway]);
    $worker = FleetFixtures::node('probe-worker');
    $worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $worker->id]);
    $node = FleetFixtures::node('probe-source', platform: $platform, managed: false);
    $node->update(['architecture' => null, 'status' => 'provisioning']);
    $input = ['bootstrap_host' => '192.0.2.10', 'bootstrap_port' => 2222, 'bootstrap_fingerprint' => 'SHA256:'.str_repeat('A', 43),
        'bootstrap_user' => $platform === 'linux' ? 'root' : 'mini', 'bootstrap_identity' => ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $node->id],
        'command_input' => ['expected_architecture' => $expected]];
    $operation = Operation::query()->create(['kind' => 'node.bootstrap_probe', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => $input, 'status' => 'queued', 'sequence' => 1, 'attempts' => 0]);
    $test->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.bootstrap_probe', 'worker' => 'probe'])->assertOk();

    return [$node, $operation, ['worker' => 'probe', 'key' => 'bootstrap-probe', 'transport' => 'bootstrap-ssh', 'name' => 'node.probe-bootstrap', 'input' => $input]];
}

function bootstrapProbeReplies(string $architecture, string $fingerprint): void
{
    $scanner = Mockery::mock(HostKeyScanner::class);
    $key = new HostKey('ssh-ed25519', 'probe-public-key', $fingerprint);
    $scanner->shouldReceive('scan')->once()->with('192.0.2.10', 2222, null)->andReturn($key);
    app()->instance(HostKeyScanner::class, $scanner);
    $known = Mockery::mock(KnownHostsStore::class);
    $known->shouldReceive('put')->once()->with('192.0.2.10', 2222, $key);
    $known->shouldReceive('path')->andReturn('/fixture/known-hosts');
    app()->instance(KnownHostsStore::class, $known);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->once()->andReturn('/fixture/probe-auth');
    app()->instance(SshKeyProvider::class, $keys);
    $runner = Mockery::mock(AdmissionAgentRunner::class);
    $runner->shouldReceive('architectureOver')->once()->withArgs(static fn (Node $node, $connection): bool => $node->architecture === null
        && $connection->host === '192.0.2.10' && $connection->identityFile === '/fixture/probe-auth' && ! $connection->shareConnection)->andReturn($architecture);
    $runner->shouldNotReceive('installOver');
    $runner->shouldNotReceive('runOver');
    app()->instance(AdmissionAgentRunner::class, $runner);
    $installer = Mockery::mock(NodeOperationAgentInstaller::class);
    $installer->shouldNotReceive('installEffect');
    $installer->shouldNotReceive('effectReceipt');
    app()->instance(NodeOperationAgentInstaller::class, $installer);
}

it('records a proven bootstrap architecture before any agent build is selected or installed', function (string $platform, string $architecture): void {
    [$node, $operation, $body] = bootstrapProbeOperation($this, $platform);
    bootstrapProbeReplies($architecture, $operation->input['bootstrap_fingerprint']);
    $result = $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', $body)->assertOk()
        ->assertJsonPath('data.probed', true)->assertJsonPath('data.architecture', $architecture)->assertDontSee('/fixture/probe-auth')->json('data');
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', $body)->assertOk();
    expect($node->fresh()->architecture)->toBeNull();
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/step', ['worker' => 'probe', 'key' => 'bootstrap-probe', 'name' => 'bootstrap-probe', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'probe', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($node->fresh()->architecture)->toBe($architecture);
    expect($node->fresh()->ssh_host_fingerprint)->toBe($body['input']['bootstrap_fingerprint']);
    expect(json_encode($operation->fresh()->toArray()))->not->toContain('/fixture/probe-auth');
})->with([['linux', 'x86_64'], ['macos', 'arm64']]);

it('refuses unsupported or mismatched bootstrap architectures before installing a binary', function (?string $expected, string $observed, string $error): void {
    [$node, $operation, $body] = bootstrapProbeOperation($this, expected: $expected);
    bootstrapProbeReplies($observed, $operation->input['bootstrap_fingerprint']);

    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', $body)->assertOk()->assertJsonPath('data.error_code', $error);
    expect($node->fresh()->architecture)->toBeNull();
    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/complete', ['worker' => 'probe', 'status' => 'succeeded', 'result' => ['probed' => true, 'architecture' => $observed]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
})->with([[null, 'arm64', 'node.architecture_unsupported'], ['arm64', 'x86_64', 'node.architecture_mismatch']]);

it('rejects changed probe connection values and commands before resolving credentials', function (string $field): void {
    [, $operation, $body] = bootstrapProbeOperation($this);
    if ($field === 'command') {
        $body['name'] = 'agent.install';
    } elseif ($field === 'expected_architecture') {
        $body['input']['command_input']['expected_architecture'] = 'x86_64';
    } else {
        $body['input'][$field] = $field === 'bootstrap_port' ? 22 : 'changed';
    }
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);

    $this->postJson('/api/v1/operation-executor/'.$operation->id.'/run', $body)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
})->with(['bootstrap_host', 'bootstrap_port', 'bootstrap_user', 'bootstrap_fingerprint', 'expected_architecture', 'command']);

it('admits the installer from the stamped probe and original delivery map under the enrollment hold', function (): void {
    $node = FleetFixtures::node('bootstrap-chain', managed: false);
    $node->update(['architecture' => null, 'status' => 'provisioning']);
    $probe = ['key' => 'add-bootstrap-probe', 'kind' => 'node.bootstrap_probe', 'node_id' => $node->id, 'input' => []];
    $install = ['key' => 'add-bootstrap-install', 'kind' => 'node.bootstrap_install', 'node_id' => $node->id, 'input' => []];
    $kind = ['executor' => 'swamp', 'execution' => NodeLifecycleOperationExecution::class,
        'completion' => NodeWireGuardOperationCompletion::class, 'runs' => [],
        'children' => [['kind' => 'node.bootstrap_probe', 'node_from' => 'operation.node_id'], ['kind' => 'node.bootstrap_install', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['step' => 'unused', 'transport' => 'gateway', 'name' => 'unused', 'result' => []], 'result_fields' => ['error_code']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(), 'node.add' => $kind]));
    $input = ['bootstrap_host' => '192.0.2.10', 'bootstrap_port' => 2222, 'bootstrap_user' => 'root',
        'bootstrap_fingerprint' => 'SHA256:'.str_repeat('A', 43), 'bootstrap_identity' => ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $node->id],
        'requested_architecture' => null, 'agent_pins' => ['linux-x86_64' => str_repeat('a', 64)],
        '_node_plan' => array_map(static fn (array $intent): array => ['type' => 'child', ...$intent, 'optional' => false], [$probe, $install])];
    $parent = app(StartOperationAction::class)->execute('node.add', 'node', $node->id, $node->id, $input, 'test', null);
    $parent->update(['state' => ['child_intents' => [$probe, $install]]]);
    $children = app(OperationChildren::class);
    expect(fn () => DB::transaction(fn () => $children->admit($parent, ['key' => $install['key']])))
        ->toThrow(ResourceOperationException::class);
    $admitted = DB::transaction(fn () => $children->admit($parent, ['key' => $probe['key']]))['operation'];
    expect($admitted->input['command_input'])->toBe(['expected_architecture' => null]);
    expect($admitted->input)->not->toHaveKey('agent_pin');
    $result = ['probed' => true, 'architecture' => 'x86_64', 'host_type' => 'ssh-ed25519', 'public_key' => 'probe-public-key',
        'host_key_fingerprint' => $input['bootstrap_fingerprint']];
    app(NodeBootstrapOperationCompletion::class)->complete($admitted, OperationOutcome::succeeded($result));
    $admitted->update(['status' => 'succeeded', 'result' => $result]);
    $node->refresh()->update(['public_ssh_host' => '192.0.2.99']);
    $installer = DB::transaction(fn () => $children->admit($parent, ['key' => $install['key']]))['operation'];

    expect($installer->input['agent_pin'])->toBe(str_repeat('a', 64));
    expect($installer->input['bootstrap_host'])->toBe('192.0.2.10');
    expect($installer->lock_key)->toBeNull();
    expect($parent->fresh()->lock_key)->toBe('node:'.$node->id);
    expect($installer->input['command_input'])->toBe([]);
    $children->verify($parent, $installer);
    expect(DB::transaction(fn () => $children->admit($parent, ['key' => $install['key']]))['operation']->id)->toBe($installer->id);
    expect(fn () => DB::transaction(fn () => $children->admit($parent, ['key' => $install['key'], 'input' => ['agent_pin' => str_repeat('b', 64)]])))
        ->toThrow(ResourceOperationException::class);
});
