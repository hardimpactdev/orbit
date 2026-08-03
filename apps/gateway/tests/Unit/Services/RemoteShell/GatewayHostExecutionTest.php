<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\RemoteShell\GatewayHostExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('forces remote host only for an active gateway node in a containerized runtime', function (): void {
    $previous = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
    putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');

    try {
        $gateway = Node::factory()
            ->gateway()
            ->create([
                'status' => NodeStatus::Active,
            ]);

        expect(GatewayHostExecution::shouldForceRemoteHostFor($gateway))->toBeTrue();
    } finally {
        if ($previous === false) {
            putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        } else {
            putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previous}");
        }
    }
});

it('does not force remote host for agent or other non-gateway targets', function (): void {
    $previous = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
    putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');

    try {
        $agent = Node::factory()->create([
            'status' => NodeStatus::Active,
            'name' => 'agent-1',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $agent->id,
            'role' => 'agent',
            'status' => 'active',
        ]);
        $app = createTestAppHostNode(['name' => 'beast']);

        expect(GatewayHostExecution::shouldForceRemoteHostFor($agent))
            ->toBeFalse()
            ->and(GatewayHostExecution::shouldForceRemoteHostFor($app))
            ->toBeFalse();
    } finally {
        if ($previous === false) {
            putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        } else {
            putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previous}");
        }
    }
});

it('does not force remote host for unsaved non-gateway nodes without querying role tables', function (): void {
    $previous = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
    putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');

    try {
        $node = new Node(['name' => 'beast', 'status' => NodeStatus::Active->value]);

        expect(GatewayHostExecution::shouldForceRemoteHostFor($node))->toBeFalse();
    } finally {
        if ($previous === false) {
            putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        } else {
            putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previous}");
        }
    }
});

it('exposes containerized gateway runtime detection for shared host-boundary callers', function (): void {
    $previousExposure = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
    $previousHost = getenv('ORBIT_HOST_PATH');
    $previousSource = getenv('ORBIT_SOURCE_PATH');
    putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
    putenv('ORBIT_HOST_PATH');
    putenv('ORBIT_SOURCE_PATH');

    try {
        expect(GatewayHostExecution::isContainerizedGatewayRuntime())->toBeFalse();

        putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');
        expect(GatewayHostExecution::isContainerizedGatewayRuntime())->toBeTrue();

        putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        putenv('ORBIT_HOST_PATH=/mnt/orbit-host');
        expect(GatewayHostExecution::isContainerizedGatewayRuntime())->toBeTrue();

        putenv('ORBIT_HOST_PATH');
        putenv('ORBIT_SOURCE_PATH=/opt/orbit');
        expect(GatewayHostExecution::isContainerizedGatewayRuntime())->toBeTrue();

        putenv('ORBIT_SOURCE_PATH=/srv/orbit');
        expect(GatewayHostExecution::isContainerizedGatewayRuntime())->toBeFalse();
    } finally {
        foreach ([
            'ORBIT_GATEWAY_EXPOSURE_MODE' => $previousExposure,
            'ORBIT_HOST_PATH' => $previousHost,
            'ORBIT_SOURCE_PATH' => $previousSource,
        ] as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
    }
});

it('does not force remote host when the gateway runtime is not containerized', function (): void {
    $previousExposure = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
    $previousHost = getenv('ORBIT_HOST_PATH');
    $previousSource = getenv('ORBIT_SOURCE_PATH');
    putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
    putenv('ORBIT_HOST_PATH');
    putenv('ORBIT_SOURCE_PATH');

    try {
        $gateway = Node::factory()
            ->gateway()
            ->create([
                'status' => NodeStatus::Active,
            ]);

        expect(GatewayHostExecution::shouldForceRemoteHostFor($gateway))->toBeFalse();
    } finally {
        foreach ([
            'ORBIT_GATEWAY_EXPOSURE_MODE' => $previousExposure,
            'ORBIT_HOST_PATH' => $previousHost,
            'ORBIT_SOURCE_PATH' => $previousSource,
        ] as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
    }
});
