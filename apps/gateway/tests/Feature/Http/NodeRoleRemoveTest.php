<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('uses target write authority for Metrics removal and keeps absent ingress a no-op', function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $make = static fn (string $name, string $ip): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-key',
    ]));
    $gateway = $make('gateway', '10.44.0.1');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $caller = $make('operator', '10.44.0.9');
    $node = $make('target', '10.44.0.2');
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $url = '/api/v1/nodes/'.$node->id.'/roles/';

    $this->deleteJson($url.'metrics', ['force' => true])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    $caller->accessibleNodes()->attach($node);
    $this->deleteJson($url.'metrics', ['force' => true])->assertNotFound()->assertJsonPath('error.code', 'http.404');
    $this->deleteJson($url.'ingress', ['force' => true])->assertOk()->assertJsonPath('data.skipped', 'node_role.absent');
    expect(Operation::query()->count())->toBe(0)->and($node->roles()->count())->toBe(0);
    Http::assertNothingSent();
});
