<?php

declare(strict_types=1);

use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

function lanRecordedNode(string $name, string $ip, array $extra = []): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit', ...$extra,
    ]));
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Process::fake();
    $this->caller = lanRecordedNode('caller', '10.44.0.9');
    $this->node = lanRecordedNode('vpn', '10.44.0.1', ['ssh_host_fingerprint' => 'SHA256:recorded']);
    $this->caller->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
});

it('sets and clears the LAN address of an existing Node as a record, without an Operation', function (): void {
    $this->patchJson("/api/v1/nodes/{$this->node->id}", ['lan_ip' => '10.0.0.2'])
        ->assertOk()->assertJsonPath('data.lan_ip', '10.0.0.2');
    expect($this->node->refresh()->lan_ip)->toBe('10.0.0.2');

    $before = $this->node->refresh()->updated_at;
    $this->travel(5)->seconds();
    $this->patchJson("/api/v1/nodes/{$this->node->id}", ['lan_ip' => '10.0.0.2'])->assertOk();
    expect($this->node->refresh()->updated_at->equalTo($before))->toBeTrue();

    $this->patchJson("/api/v1/nodes/{$this->node->id}", ['lan_ip' => null])
        ->assertOk()->assertJsonPath('data.lan_ip', null);
    expect($this->node->refresh()->lan_ip)->toBeNull()
        ->and(DB::table('operations')->count())->toBe(0);
});

it('refuses a LAN address that is not IPv4, or that another Node of its Cluster has', function (): void {
    $this->patchJson("/api/v1/nodes/{$this->node->id}", ['lan_ip' => 'fd00::1'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    $cluster = Cluster::query()->create(['name' => 'prod']);
    $this->node->forceFill(['cluster_id' => $cluster->id])->saveQuietly();
    lanRecordedNode('app-prod', '10.44.0.4', ['cluster_id' => $cluster->id, 'lan_ip' => '10.0.0.4']);

    $this->patchJson("/api/v1/nodes/{$this->node->id}", ['lan_ip' => '10.0.0.4'])
        ->assertStatus(409)->assertJsonPath('error.code', 'cluster.lan_ip_conflict');
    expect($this->node->refresh()->lan_ip)->toBeNull();
});
