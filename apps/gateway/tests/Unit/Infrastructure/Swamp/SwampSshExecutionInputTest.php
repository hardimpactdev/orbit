<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use Tests\TestCase;

uses(TestCase::class);

it('exposes the approved public 209 identity before a bootstrap target exists', function (): void {
    expect((new SwampSshExecutionInput)->identity())->toBe([
        'control_node_id' => 209,
        'identity_file' => '/run/credentials/orbit-node-worker.service/proxy-ssh-identity',
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND',
    ]);
});

it('captures official SSH arguments from the stored target without a Gateway key or host lookup', function (): void {
    $node = new Node(['user' => 'app', 'wireguard_ip' => '10.44.0.9',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'target-public-key']);

    $input = (new SwampSshExecutionInput)->forNode($node);

    expect($input['control_node_id'])->toBe(209);
    expect($input['target'])->toBe(['name' => 'target', 'host' => '10.44.0.9', 'user' => 'app', 'port' => 22,
        'host_keys' => ['ssh-ed25519 target-public-key']]);
});

it('refuses an incomplete target instead of selecting an unpinned connection', function (string $field): void {
    $node = new Node(['user' => 'app', 'wireguard_ip' => '10.44.0.9',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'target-public-key']);
    $node->setAttribute($field, '');

    expect(fn () => (new SwampSshExecutionInput)->forNode($node))->toThrow(ResourceOperationException::class,
        'Official SSH requires the stored target address, user and host key.');
})->with(['user', 'wireguard_ip', 'ssh_host_key_type', 'ssh_host_key']);

it('captures the configured topology SSH identity without changing target pins', function (): void {
    config()->set('swamp.ssh_public_key', 'ssh-ed25519 fixture-topology-key');
    expect((new SwampSshExecutionInput)->identity()['public_key'])->toBe('ssh-ed25519 fixture-topology-key');
});
