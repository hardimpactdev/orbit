<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Operations\FleetUpdateTargetSelector;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('selects only active Agent-eligible workload nodes', function (): void {
    $eligible = Node::factory()->create([
        'name' => 'eligible-app',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
        'wireguard_address' => '10.6.0.40',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $eligible->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    $unsupported = Node::factory()->create([
        'name' => 'unsupported-app',
        'platform' => 'windows_11',
        'status' => 'active',
        'wireguard_address' => '10.6.0.41',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $unsupported->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    $unbound = Node::factory()->create([
        'name' => 'unbound-database',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
        'wireguard_address' => null,
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $unbound->id,
        'role' => 'database',
        'status' => 'active',
    ]);

    $inactive = Node::factory()->create([
        'name' => 'inactive-app',
        'platform' => 'ubuntu_24-04',
        'status' => 'inactive',
        'wireguard_address' => '10.6.0.42',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $inactive->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    $vpn = Node::factory()->create([
        'name' => 'vpn-node',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
        'wireguard_address' => '10.6.0.44',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $vpn->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);

    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway',
            'platform' => 'debian_12',
            'status' => 'active',
            'wireguard_address' => '10.6.0.45',
        ]);

    $operator = Node::factory()
        ->operator()
        ->create([
            'name' => 'managed-operator',
            'managed' => true,
            'platform' => 'macos_15-5',
            'status' => 'active',
            'wireguard_address' => '10.6.0.43',
        ]);

    $selector = app(FleetUpdateTargetSelector::class);
    $run = app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );

    expect($operator->isAgentEligible())
        ->toBeTrue()
        ->and($selector->activeNonGatewayRoleNodes()->pluck('name')->all())
        ->toBe([
            'eligible-app',
            'unbound-database',
            'unsupported-app',
            'vpn-node',
        ])
        ->and($selector->workloadNodes($run)->pluck('name')->all())
        ->toBe(['eligible-app']);
});
