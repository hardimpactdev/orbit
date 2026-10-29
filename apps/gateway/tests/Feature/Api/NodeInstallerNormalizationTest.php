<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\Nodes\BootstrapSshOperationTransport;
use App\Infrastructure\Nodes\ManagedAgentInstallOperationTransport;
use App\Infrastructure\Nodes\NodeOperationAgentInstaller;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

function installerNormalizationReceipt(array $result): array
{
    return ['effect_key' => 'op-7.agent-install', 'state' => 'succeeded', 'result' => $result,
        'sanitized' => true, 'effect_nonce' => '8d66ea16-8f73-44b1-a501-211a3e41cd78', 'effect_created_at' => 1793232000,
        'result_digest' => str_repeat('b', 64), 'exit_code' => 0, 'finished_at' => '2026-10-29T00:00:00Z'];
}

it('preserves internal receipt identity when normalizing a managed installer result', function (): void {
    $node = FleetFixtures::node('managed-normalizer');
    $operation = (new Operation)->forceFill(['id' => 7, 'node_id' => $node->id, 'input' => ['_footprint' => ['agent_pin' => str_repeat('a', 64)]]]);
    $original = installerNormalizationReceipt(['replaced' => true]);
    $installer = Mockery::mock(NodeOperationAgentInstaller::class);
    $installer->shouldReceive('effectReceipt')->once()->andReturn($original);

    $result = (new ManagedAgentInstallOperationTransport($installer))->receipt($operation, ['name' => 'agent.install', 'effect_key' => 'op-7.agent-install'], []);

    expect($result)->toBe([...$original, 'result' => ['installed' => true, 'changed' => true]]);
});

it('refuses a success receipt that has no original installer replacement result', function (): void {
    $node = FleetFixtures::node('managed-missing-result');
    $operation = (new Operation)->forceFill(['id' => 7, 'node_id' => $node->id, 'input' => ['_footprint' => ['agent_pin' => str_repeat('a', 64)]]]);
    $installer = Mockery::mock(NodeOperationAgentInstaller::class);
    $installer->shouldReceive('effectReceipt')->once()->andReturn(installerNormalizationReceipt([]));

    expect(fn () => (new ManagedAgentInstallOperationTransport($installer))->receipt($operation, ['name' => 'agent.install', 'effect_key' => 'op-7.agent-install'], []))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.receipt_unproven'));
});

it('preserves bootstrap receipt identity while using its connection credential only for authentication', function (): void {
    $node = FleetFixtures::node('bootstrap-normalizer');
    $fingerprint = 'SHA256:'.str_repeat('A', 43);
    $node->update(['ssh_host_fingerprint' => $fingerprint]);
    $input = ['agent_pin' => str_repeat('a', 64), 'bootstrap_host' => $node->public_ssh_host, 'bootstrap_port' => 22,
        'bootstrap_user' => 'orbit', 'bootstrap_fingerprint' => $fingerprint,
        'bootstrap_identity' => ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $node->id], 'command_input' => []];
    $operation = (new Operation)->forceFill(['id' => 7, 'kind' => 'node.bootstrap_install', 'node_id' => $node->id,
        'target_type' => 'node_bootstrap', 'target_id' => $node->id, 'input' => $input]);
    $run = ['worker' => 'normalizer', 'key' => 'agent-install', 'transport' => 'bootstrap-ssh', 'name' => 'agent.install', 'input' => $input, 'effect_key' => 'op-7.agent-install'];
    $key = new HostKey('ssh-ed25519', 'public-host-key', $fingerprint);
    $scanner = Mockery::mock(HostKeyScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn($key);
    $known = Mockery::mock(KnownHostsStore::class);
    $known->shouldReceive('put')->once();
    $known->shouldReceive('path')->andReturn('/fixture/known-hosts');
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->once()->andReturn('/fixture/existing-connection');
    app()->instance(SshKeyProvider::class, $keys);
    $agents = Mockery::mock(AdmissionAgentRunner::class);
    $agents->shouldReceive('architectureOver')->once()->andReturn('x86_64');
    $agents->shouldNotReceive('runOver');
    $original = installerNormalizationReceipt(['replaced' => false]);
    $installer = Mockery::mock(NodeOperationAgentInstaller::class);
    $installer->shouldReceive('effectReceipt')->once()->withArgs(static fn ($target, $pin, $command, $effectKey, $connection): bool => $target->id === $node->id && $connection->identityFile === '/fixture/existing-connection' && $effectKey === 'op-7.agent-install')->andReturn($original);

    $receipt = (new BootstrapSshOperationTransport($agents, $installer, $scanner, $known))->receipt($operation, $run, $input);

    expect($receipt)->toBe([...$original, 'result' => ['installed' => true, 'changed' => false, 'architecture' => 'x86_64',
        'host_type' => 'ssh-ed25519', 'public_key' => 'public-host-key', 'host_key_fingerprint' => $fingerprint]]);
    expect(json_encode($receipt))->not->toContain('/fixture/existing-connection');
});
