<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('derives managed Agent eligibility from a supported workload role', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'managed' => false,
        'wireguard_address' => '10.6.0.44',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
    ]);

    expect($node->fresh()->isAgentEligible())->toBeTrue();
});

it('preserves Agent eligibility while the last workload role is being removed', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'managed' => false,
        'wireguard_address' => '10.6.0.48',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'removing',
    ]);

    expect($node->fresh()->isAgentEligible())->toBeTrue();
});

it('accepts explicit managed opt-in only for a roleless supported operator identity', function (): void {
    $operator = Node::factory()->create([
        'platform' => 'macos_26-5-1',
        'managed' => true,
        'wireguard_address' => '10.6.0.45',
    ]);
    $gateway = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'managed' => true,
        'wireguard_address' => '10.6.0.2',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    expect($operator->isAgentEligible())
        ->toBeTrue()
        ->and($gateway->fresh()->isAgentEligible())
        ->toBeFalse();
});

it('excludes gateway-role nodes from fleet-update eligibility', function (): void {
    $gateway = Node::factory()
        ->gateway()
        ->create([
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.2',
        ]);
    $workload = Node::factory()
        ->operator()
        ->create([
            'managed' => false,
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.96',
        ]);

    expect($gateway->fresh()->isFleetUpdateEligible())->toBeFalse();
    expect($workload->isFleetUpdateEligible())->toBeTrue();
});

it('fails closed without a supported platform or WireGuard identity', function (): void {
    $unsupported = Node::factory()->create([
        'platform' => 'windows_11',
        'managed' => true,
        'wireguard_address' => '10.6.0.46',
    ]);
    $unbound = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'managed' => true,
        'wireguard_address' => null,
    ]);

    expect($unsupported->isAgentEligible())
        ->toBeFalse()
        ->and($unbound->isAgentEligible())
        ->toBeFalse();
});

it('admits provisioning nodes only after workload Agent intent is persisted', function (): void {
    $node = Node::factory()->create([
        'status' => 'provisioning',
        'platform' => 'ubuntu_24-04',
        'managed' => false,
        'wireguard_address' => '10.6.0.47',
    ]);

    expect($node->isAgentEligible())->toBeFalse();

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'pending',
    ]);

    expect($node->fresh()->isAgentEligible())->toBeTrue();
});

it('generates factory WireGuard identities from a deterministic private unicast range', function (): void {
    $nodes = Node::factory()->appDev()->count(32)->create();

    foreach ($nodes as $node) {
        expect($node->wireguard_address)
            ->toStartWith('10.250.')
            ->and($node->isAgentEligible())
            ->toBeTrue();
    }
});
