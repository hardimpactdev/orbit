<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeOperationAgentInstaller;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;

function bootstrapOperation($test): array
{
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.1']);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.2']);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $node = Node::query()->create(['name' => 'new', 'status' => LifecycleStatus::Provisioning, 'platform' => 'linux',
        'architecture' => 'x86_64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.3']);
    $input = ['agent_pin' => app(AgentDelivery::class)->pin($node), 'bootstrap_host' => '192.0.2.10', 'bootstrap_port' => 2222,
        'bootstrap_fingerprint' => 'SHA256:'.str_repeat('A', 43), 'bootstrap_user' => 'root', 'bootstrap_identity' => ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $node->id], 'command_input' => []];
    $operation = Operation::query()->create(['kind' => 'node.bootstrap_install', 'target_type' => 'node_bootstrap',
        'target_id' => $node->id, 'node_id' => $node->id, 'input' => $input, 'status' => 'queued', 'sequence' => 1, 'attempts' => 0]);
    $test->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.bootstrap_install', 'worker' => 'bootstrap-1'])->assertOk();

    return [$node, $operation, ['worker' => 'bootstrap-1', 'key' => 'install', 'transport' => 'bootstrap-ssh',
        'name' => 'agent.install', 'input' => $input]];
}

it('pins bootstrap SSH before installing and never uses the future WireGuard address', function (): void {
    [$node, $operation, $body] = bootstrapOperation($this);
    $key = new HostKey('ssh-ed25519', 'public-key', $operation->input['bootstrap_fingerprint']);
    $scanner = Mockery::mock(HostKeyScanner::class);
    $scanner->shouldReceive('scan')->once()->with('192.0.2.10', 2222, null)->andReturn($key);
    app()->instance(HostKeyScanner::class, $scanner);
    $known = Mockery::mock(KnownHostsStore::class);
    $known->shouldReceive('put')->once()->with('192.0.2.10', 2222, $key);
    $known->shouldReceive('path')->andReturn('/fake/known-hosts');
    app()->instance(KnownHostsStore::class, $known);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->once()->andReturn('/fake/gateway-identity');
    app()->instance(SshKeyProvider::class, $keys);
    $runner = Mockery::mock(AdmissionAgentRunner::class);
    $connection = static fn (SshConnection $connection): bool => $connection->host === '192.0.2.10'
        && $connection->port === 2222 && $connection->user === 'root'
        && $connection->hostIdentity === 'SHA256:'.str_repeat('A', 43)
        && $connection->identityFile === '/fake/gateway-identity' && ! $connection->shareConnection;
    $runner->shouldReceive('architectureOver')->once()->withArgs(fn (Node $target, SshConnection $ssh): bool => $target->id === $node->id && $connection($ssh))->andReturn('x86_64');
    $installer = Mockery::mock(NodeOperationAgentInstaller::class);
    $installer->shouldReceive('installEffect')->once()->withArgs(fn (Node $target, string $pin, array $run, SshConnection $ssh): bool => $target->architecture === 'x86_64' && $connection($ssh) && $pin === $operation->input['agent_pin'] && $run['effect_key'] === 'op-'.$operation->id.'.install')->andReturn(true);
    app()->instance(NodeOperationAgentInstaller::class, $installer);
    app()->instance(AdmissionAgentRunner::class, $runner);

    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)->assertOk()->assertJsonPath('data.installed', true);
    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)->assertOk();
});

it('refuses a changed host, port, fingerprint, login or bootstrap command before SSH', function (string $field): void {
    [, $operation, $body] = bootstrapOperation($this);
    $scanner = Mockery::mock(HostKeyScanner::class);
    $scanner->shouldNotReceive('scan');
    app()->instance(HostKeyScanner::class, $scanner);
    if ($field === 'command') {
        $body['name'] = 'gateway.switch-release';
    } else {
        $body['input'][$field] = $field === 'bootstrap_port' ? 22 : 'changed';
    }

    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
})->with(['bootstrap_host', 'bootstrap_port', 'bootstrap_fingerprint', 'bootstrap_user', 'command']);

it('refuses the wrong observed fingerprint before installing', function (): void {
    [, $operation, $body] = bootstrapOperation($this);
    $scanner = Mockery::mock(HostKeyScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn(new HostKey('ssh-ed25519', 'different', 'SHA256:'.str_repeat('B', 43)));
    app()->instance(HostKeyScanner::class, $scanner);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fake/gateway-identity');
    app()->instance(SshKeyProvider::class, $keys);
    $runner = Mockery::mock(AdmissionAgentRunner::class);
    $runner->shouldNotReceive('installOver');
    app()->instance(AdmissionAgentRunner::class, $runner);

    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)
        ->assertConflict()->assertJsonPath('error.code', 'node.ssh_host_key_mismatch');
});

it('refuses an SSH identity reference owned by another Node before resolving it', function (): void {
    [, $operation, $body] = bootstrapOperation($this);
    $body['input']['bootstrap_identity']['id']++;
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);

    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('does not accept bare or forged bootstrap completion', function (): void {
    [, $operation] = bootstrapOperation($this);
    $this->postJson("/api/v1/operation-executor/{$operation->id}/complete", [
        'worker' => 'bootstrap-1', 'status' => 'succeeded', 'result' => ['installed' => true],
    ])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson("/api/v1/operation-executor/{$operation->id}/step", [
        'worker' => 'bootstrap-1', 'key' => 'forged', 'name' => 'agent-install', 'status' => 'succeeded', 'result' => ['installed' => true],
    ])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
});

it('recovers only the matching original bootstrap receipt using the existing connection identity', function (string $case): void {
    [$node, $operation, $body] = bootstrapOperation($this);
    $key = new HostKey('ssh-ed25519', 'public-key', $operation->input['bootstrap_fingerprint']);
    $scanner = Mockery::mock(HostKeyScanner::class);
    $scanner->shouldReceive('scan')->twice()->with('192.0.2.10', 2222, null)->andReturn($key);
    app()->instance(HostKeyScanner::class, $scanner);
    $known = Mockery::mock(KnownHostsStore::class);
    $known->shouldReceive('put')->twice()->with('192.0.2.10', 2222, $key);
    $known->shouldReceive('path')->andReturn('/fake/known-hosts');
    app()->instance(KnownHostsStore::class, $known);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->twice()->andReturn('/fake/existing-identity');
    app()->instance(SshKeyProvider::class, $keys);
    $runner = Mockery::mock(AdmissionAgentRunner::class);
    $runner->shouldReceive('architectureOver')->twice()->andReturn('x86_64');
    $runner->shouldNotReceive('installOver');
    $runner->shouldNotReceive('runOver');
    app()->instance(AdmissionAgentRunner::class, $runner);
    $installer = Mockery::mock(NodeOperationAgentInstaller::class);
    $installer->shouldReceive('installEffect')->once()->andThrow(new RuntimeException('lost reply'));
    $installer->shouldReceive('effectReceipt')->once()->withArgs(static fn (Node $target, string $pin, string $command, string $effectKey, SshConnection $connection): bool => $target->id === $node->id && $pin === $operation->input['agent_pin'] && $command === 'agent.install'
        && $effectKey === 'op-'.$operation->id.'.install' && $connection->identityFile === '/fake/existing-identity'
        && $connection->host === '192.0.2.10' && $connection->hostIdentity === $key->fingerprint
    )->andReturnUsing(static function () use ($operation, $case): array {
        $identity = $operation->fresh()->state['executor_effect_identity'];

        return ['effect_key' => 'op-'.$operation->id.'.install', 'state' => 'succeeded', 'result' => ['replaced' => true],
            'sanitized' => $case !== 'unsanitized', 'effect_nonce' => $case === 'wrong nonce' ? '1d66ea16-8f73-44b1-a501-211a3e41cd78' : $identity['nonce'],
            'effect_created_at' => $identity['created_at'] + ($case === 'wrong creation' ? 1 : 0),
            'exit_code' => 0, 'finished_at' => '2026-10-29T00:00:00Z'];
    });
    app()->instance(NodeOperationAgentInstaller::class, $installer);

    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $before = $operation->fresh()->state;
    expect($before['executor_runs']['install']['result'])->toBeNull();
    if ($case !== 'matching') {
        $this->getJson("/api/v1/operation-executor/{$operation->id}/receipts/install")
            ->assertConflict()->assertJsonPath('error.code', $case === 'unsanitized' ? 'operation.receipt_unproven' : 'operation.execution_uncertain');
        expect($operation->fresh()->state)->toBe($before);
        $this->postJson("/api/v1/operation-executor/{$operation->id}/complete", [
            'worker' => 'bootstrap-1', 'status' => 'succeeded', 'result' => ['installed' => true],
        ])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
        expect($operation->fresh()->state)->toBe($before);

        return;
    }
    $this->getJson("/api/v1/operation-executor/{$operation->id}/receipts/install")->assertOk()
        ->assertJsonPath('data.state', 'succeeded')->assertJsonPath('data.result.installed', true)
        ->assertJsonPath('data.result.changed', true)->assertDontSee('/fake/existing-identity');
    $this->getJson("/api/v1/operation-executor/{$operation->id}/receipts/install")->assertOk();
    expect($operation->fresh()->state['executor_effect_identity'])->toBe($before['executor_effect_identity']);
    expect(json_encode($operation->fresh()->state))->not->toContain('/fake/existing-identity');
})->with(['matching', 'wrong nonce', 'wrong creation', 'unsanitized']);

it('refuses connection credentials when the Operation resource is another Node', function (): void {
    [, $operation, $body] = bootstrapOperation($this);
    $operation->update(['target_id' => $operation->target_id + 1]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);

    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
});
