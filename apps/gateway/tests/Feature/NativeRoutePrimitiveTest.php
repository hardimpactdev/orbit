<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Routes\RouteOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

it('declares full-input native terminal ownership for the remaining Route primitives', function (string $kind): void {
    $node = Node::query()->create(['name' => 'primitive', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin']);
    $operation = new Operation(['kind' => $kind, 'node_id' => $node->id]);
    $direct = app(DirectOperation::class);
    expect($direct->definition($kind)['workflow_inputs'] ?? false)->toBeTrue()
        ->and($direct->usesNativeTerminal($operation))->toBeTrue()
        ->and($direct->definition($kind))->not->toHaveKey('primitive');
})->with(['route.workload_verify', 'route.public_verify', 'route.private_dns_publish']);

it('keeps the admitted probe connection unchanged when Node data changes', function (): void {
    $node = Node::query()->create(['name' => 'primitive', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin']);
    $intent = ['protocol' => 'tls', 'probe' => ['address' => '10.44.0.9', 'port' => 443, 'domain' => 'example.test'],
        'control_node_id' => 209, 'connection' => ['nodeHost' => '10.44.0.8', 'nodeUser' => 'orbit', 'nodePort' => 22, 'nodeIdentityFile' => '/run/credentials/worker/key', 'become' => true],
        'host_keys' => ['ssh-ed25519 admitted-pin']];
    $operation = new Operation(['kind' => 'route.workload_verify', 'target_type' => 'route_effect', 'node_id' => $node->id, 'input' => ['verification' => $intent]]);
    $node->update(['wireguard_ip' => '10.44.0.99', 'user' => 'changed']);
    expect(app(RouteOperationProjection::class)->input($operation))->toBe(['intent' => ['node_id' => $node->id, ...$intent], 'execution_secrets' => []]);
});

it('captures the full DNS catalog and pins before native dispatch', function (): void {
    $node = Node::query()->create(['name' => 'dns', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin']);
    $node->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $operation = new Operation(['kind' => 'route.private_dns_publish', 'node_id' => $node->id]);
    $capture = app(RouteOperationProjection::class)->input($operation);
    $node->update(['wireguard_ip' => '10.44.0.99']);
    expect($capture['intent']['connection']['nodeHost'])->toBe('10.44.0.9')
        ->and($capture['intent']['host_keys'])->toBe(['ssh-ed25519 fixture-pin'])
        ->and($capture['intent']['after']['digest'])->toBe(hash('sha256', $capture['intent']['after']['catalog']))
        ->and($capture['execution_secrets'])->toBe([]);
});

it('refuses DNS publication from a Node without an active VPN role', function (): void {
    $node = Node::query()->create(['name' => 'not-dns', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']);
    expect(fn () => app(RouteOperationProjection::class)->input(new Operation(['kind' => 'route.private_dns_publish', 'node_id' => $node->id])))
        ->toThrow(ResourceOperationException::class);
});
