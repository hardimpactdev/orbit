<?php

declare(strict_types=1);

use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('orbit:internal:bake-app-node', function (): void {
    beforeEach(function (): void {
        $this->hostKeyPinner = new class
        {
            /** @var list<array{host: string, expected: ?string}> */
            public array $calls = [];

            public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
            {
                $this->calls[] = ['host' => $host, 'expected' => $expectedFingerprint];

                return new PinnedHostKey(
                    host: $host,
                    type: 'ssh-ed25519',
                    publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIBakeAppNodeHostKey',
                    fingerprint: 'SHA256:bake-app-node-host-key',
                    pinMode: 'tofu',
                );
            }
        };

        app()->instance(SshHostKeyPinner::class, $this->hostKeyPinner);
    });

    it('writes an app node row with the same shape as node:new produces', function (): void {
        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-dev-1',
            '--role' => 'app-dev',
            '--host' => '10.6.0.4',
            '--wireguard-address' => '10.6.0.4',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--tld' => 'test',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'app-dev-1')->firstOrFail();

        expect($node->getAttributes())->not->toHaveKeys(['role', 'environment'])
            ->and($node->host)->toBe('10.6.0.4')
            ->and($node->wireguard_address)->toBe('10.6.0.4')
            ->and($node->gateway_endpoint)->toBe('10.6.0.2')
            ->and($node->user)->toBe('orbit')
            ->and($node->orbit_path)->toBe('/home/orbit/orbit')
            ->and($node->tld)->toBe('test')
            ->and($node->status)->toBe('active')
            ->and($node->host_key_type)->toBe('ssh-ed25519')
            ->and($node->host_key_public)->toBe('AAAAC3NzaC1lZDI1NTE5AAAAIBakeAppNodeHostKey')
            ->and($node->host_key_fingerprint)->toBe('SHA256:bake-app-node-host-key')
            ->and($node->host_key_pin_mode)->toBe('tofu')
            ->and($node->host_key_pinned_at)->not->toBeNull()
            ->and($this->hostKeyPinner->calls)->toBe([
                ['host' => '10.6.0.4', 'expected' => null],
            ]);
    });

    it('writes the matching active composable role assignment', function (): void {
        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-dev-1',
            '--role' => 'app-dev',
            '--host' => 'dev',
            '--wireguard-address' => '10.6.0.4',
            '--gateway-endpoint' => 'gateway',
            '--user' => 'orbit',
            '--tld' => 'test',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'app-dev-1')->firstOrFail();
        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', NodeRoleName::AppDevelopment->value)
            ->first();

        expect($assignment)->not->toBeNull()
            ->and($assignment?->status)->toBe(NodeRoleStatus::Active->value)
            ->and($assignment?->settings)->toBe(['tld' => 'test']);
    });

    it('is idempotent across repeated runs', function (): void {
        $args = [
            'name' => 'app-prod-1',
            '--role' => 'app-prod',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
        ];

        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();
        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();

        $node = Node::query()->where('name', 'app-prod-1')->firstOrFail();

        expect(Node::query()->where('name', 'app-prod-1')->count())->toBe(1)
            ->and($node->tld)->toBeNull()
            ->and(NodeRoleAssignment::query()
                ->where('node_id', $node->id)
                ->where('role', NodeRoleName::AppProduction->value)
                ->count())->toBe(1);
    });

    it('stores the selected ingress node for production placement', function (): void {
        $edge = Node::factory()->create([
            'name' => 'edge-1',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $edge->id,
            'role' => NodeRoleName::Ingress->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-prod-1',
            '--role' => 'app-prod',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--ingress-node' => 'edge-1',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'app-prod-1')->firstOrFail();
        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', NodeRoleName::AppProduction->value)
            ->first();

        expect($assignment)->not->toBeNull()
            ->and($assignment?->settings)->toBe(['ingress_node_id' => $edge->id]);
    });

    it('removes stale colocated ingress from production app nodes when dedicated ingress is selected', function (): void {
        $edge = Node::factory()->create([
            'name' => 'edge-1',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $edge->id,
            'role' => NodeRoleName::Ingress->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $appProd = Node::factory()->create([
            'name' => 'app-prod-1',
            'host' => '10.6.0.5',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $appProd->id,
            'role' => NodeRoleName::Ingress->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-prod-1',
            '--role' => 'app-prod',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--ingress-node' => 'edge-1',
        ])->assertSuccessful();

        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $appProd->id)
            ->where('role', NodeRoleName::AppProduction->value)
            ->first();

        expect($assignment)->not->toBeNull()
            ->and($assignment?->settings)->toBe(['ingress_node_id' => $edge->id])
            ->and(NodeRoleAssignment::query()
                ->where('node_id', $appProd->id)
                ->where('role', NodeRoleName::Ingress->value)
                ->exists())->toBeFalse();
    });

    it('requires the selected ingress node to have an active ingress assignment', function (): void {
        Node::factory()->create([
            'name' => 'edge-1',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
        ]);

        expect(fn () => $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-prod-1',
            '--role' => 'app-prod',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--ingress-node' => 'edge-1',
        ])->run())->toThrow(RuntimeException::class, 'Active ingress node [edge-1] was not found.');
    });
});
