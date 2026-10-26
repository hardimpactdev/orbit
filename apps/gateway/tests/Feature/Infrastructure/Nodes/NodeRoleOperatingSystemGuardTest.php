<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Nodes\Roles\NodeRoleOperatingSystemGuard;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

function node_role_guard_node(): Node
{
    return new Node(['name' => 'guard-node', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9']);
}

it('accepts a supported Ubuntu release for every role', function (RoleName $role): void {
    $agents = (new FakeAgentCommandRunner)->answer('node.os-release', ['id' => 'ubuntu', 'codename' => 'resolute']);

    (new NodeRoleOperatingSystemGuard($agents))->assert(node_role_guard_node(), $role);

    expect($agents->commands())->toBe(['node.os-release']);
})->with(RoleName::cases());

it('rejects nodes without a WireGuard address before the agent', function (): void {
    $agents = new FakeAgentCommandRunner;
    $node = node_role_guard_node();
    $node->wireguard_ip = null;

    expect(fn () => (new NodeRoleOperatingSystemGuard($agents))->assert($node, RoleName::Vpn))
        ->toThrow(fn (NodeRoleOperationException $exception): bool => $exception->underlyingErrorCode === 'node_role.wireguard_missing'
            && $exception->getMessage() === 'Node [guard-node] role [vpn] requires a WireGuard address.'
            && $exception->result === null);
    expect($agents->calls)->toBe([]);
});

it('rejects unsupported operating systems with their name', function (RoleName $role, string $id, string $codename): void {
    $agents = (new FakeAgentCommandRunner)->answer('node.os-release', ['id' => $id, 'codename' => $codename]);

    expect(fn () => (new NodeRoleOperatingSystemGuard($agents))->assert(node_role_guard_node(), $role))
        ->toThrow(fn (NodeRoleOperationException $exception): bool => $exception->step === 'operating-system'
            && $exception->errorCode === 'node_role.convergence_failed'
            && $exception->underlyingErrorCode === 'node_role.operating_system_unsupported'
            && $exception->getMessage() === "Node [guard-node] role [{$role->value}]. Node operating system [{$id}/{$codename}] is not supported.");
})->with([
    'old Ubuntu for VPN' => [RoleName::Vpn, 'ubuntu', 'noble'],
    'old Ubuntu for app-dev' => [RoleName::AppDev, 'ubuntu', 'unsupported'],
    'Debian for gateway' => [RoleName::Gateway, 'debian', 'resolute'],
]);

it('rejects a Node whose os-release the agent cannot read', function (): void {
    $agents = (new FakeAgentCommandRunner)->refuse('node.os-release', 'node.os_release_unreadable');

    expect(fn () => (new NodeRoleOperatingSystemGuard($agents))->assert(node_role_guard_node(), RoleName::Gateway))
        ->toThrow(fn (NodeRoleOperationException $exception): bool => $exception->getMessage() === 'Node [guard-node] role [gateway]. Node operating system [unknown/unknown] is not supported.'
            && $exception->result?->succeeded() === false);
});
