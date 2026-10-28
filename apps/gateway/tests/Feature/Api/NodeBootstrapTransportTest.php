<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
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
        'user' => 'orbit', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.3']);
    $input = ['bootstrap_host' => '192.0.2.10', 'bootstrap_port' => 2222,
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
    $runner->shouldReceive('installOver')->once()->withArgs(fn (Node $target, SshConnection $ssh): bool => $target->architecture === 'x86_64' && $connection($ssh))->andReturn(true);
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
