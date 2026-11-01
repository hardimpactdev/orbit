<?php

declare(strict_types=1);

use App\Actions\Doctor\StartDoctorAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Firewall\FirewallInspectionInput;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FirewallProjectionFixture;

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture-token']);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    $this->node = Node::query()->forceCreate([
        'id' => 209, 'name' => 'target', 'architecture' => 'x86_64', 'platform' => 'linux',
        'status' => LifecycleStatus::Active, 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9',
        'public_ssh_port' => 2222, 'wireguard_ip' => '10.44.0.9',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-key',
    ]);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->url = '/api/v1/nodes/209/firewall-rules/allowlist';
    $this->payload = ['name' => 'rex', 'port' => '7297', 'sources' => ['10.44.0.9', '10.44.0.6']];
});

it('admits a normalized source set and replaces it under the same name then removes it', function (): void {
    $id = $this->postJson($this->url, [...$this->payload, 'sources' => ['10.44.0.9/32', '10.44.0.6', '10.44.0.6']])
        ->assertAccepted()->assertJsonPath('data.kind', 'firewall.allowlist')->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['firewall']['rule'])->toMatchArray(['action' => 'allowlist', 'protocol' => 'tcp', 'port' => '7297', 'sources' => ['10.44.0.6', '10.44.0.9']]);
    expect($this->socket->sent[0]['payload']['inputs']['intent'])->toBe($operation->input['firewall']);
    FirewallProjectionFixture::finish($operation);
    $rule = FirewallRule::query()->sole();
    $this->getJson('/api/v1/nodes/209/firewall-rules')->assertOk()->assertJsonPath('data.0.sources', ['10.44.0.6', '10.44.0.9']);
    $id = $this->postJson($this->url, [...$this->payload, 'sources' => ['10.44.0.17/28']])->assertAccepted()->json('data.id');
    FirewallProjectionFixture::finish(Operation::query()->findOrFail($id));
    expect($rule->fresh()->sources)->toBe(['10.44.0.16/28']);
    expect(FirewallRule::query()->count())->toBe(1);
    $expected = app(FirewallInspectionInput::class)->capture($this->node)['expected'];
    $block = array_values(array_filter($expected, fn (array $row): bool => $row['name'] === 'rex'));
    expect(array_column(array_column($block, 'shape'), 'action'))->toBe(['allow', 'deny']);
    expect(array_column(array_column($block, 'shape'), 'source'))->toBe(['10.44.0.16/28', 'any']);
    $id = $this->deleteJson('/api/v1/nodes/209/firewall-rules/rex')->assertAccepted()->json('data.id');
    $remove = Operation::query()->findOrFail($id);
    expect($remove->input['firewall']['ensure'])->toBe('absent');
    expect($remove->input['firewall']['rule']['sources'])->toBe(['10.44.0.16/28']);
    FirewallProjectionFixture::finish($remove);
    $this->assertModelMissing($rule);
});

it('replays an admission key and refuses changed input under that key', function (): void {
    $first = $this->withHeader('Idempotency-Key', 'rex-first')->postJson($this->url, $this->payload)->assertAccepted()->json('data.id');
    $this->postJson($this->url, $this->payload)->assertAccepted()->assertJsonPath('data.id', $first);
    $this->postJson($this->url, [...$this->payload, 'sources' => ['10.44.0.6']])->assertConflict();
    expect($this->socket->sent)->toHaveCount(1);
});

it('returns 422 for invalid input without storing or dispatching it', function (array $change): void {
    $this->postJson($this->url, [...$this->payload, ...$change])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(FirewallRule::query()->count())->toBe(0);
    expect($this->socket->sent)->toBe([]);
})->with([
    'empty' => [['sources' => []]], 'hostname' => [['sources' => ['example.test']]],
    'IPv6' => [['sources' => ['::1']]], 'any' => [['sources' => ['any']]],
    'CIDR' => [['sources' => ['10.44.0.6/33']]], 'scalar' => [['sources' => '10.44.0.6']],
    'map' => [['sources' => ['office' => '10.44.0.6']]], 'single source' => [['source' => 'any']],
    'protocol' => [['protocol' => 'sctp']], 'port' => [['port' => '0']], 'range' => [['port' => '8000:7000']],
]);

it('refuses protected ports and ranges before persistence regardless of protocol', function (string $port): void {
    NodeRole::query()->create(['node_id' => $this->node->id, 'role' => 'gateway', 'status' => 'active']);
    app(VpnSettings::class)->configure('10.44.0.0/24', 51821);
    $this->postJson($this->url, [...$this->payload, 'port' => $port, 'protocol' => 'udp'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'firewall.protected_port');
    expect(FirewallRule::query()->count())->toBe(0);
    expect($this->socket->sent)->toBe([]);
})->with(['22', '2222', '51821', '440:445']);

it('refuses overlaps and changes of port or protocol while retaining stored intent', function (): void {
    $id = $this->postJson($this->url, $this->payload)->assertAccepted()->json('data.id');
    FirewallProjectionFixture::finish(Operation::query()->findOrFail($id));
    $this->postJson($this->url, [...$this->payload, 'name' => 'other', 'port' => '7296:7298'])->assertConflict()->assertJsonPath('error.code', 'firewall.allowlist_overlap');
    $this->postJson($this->url, [...$this->payload, 'port' => '7298'])->assertConflict()->assertJsonPath('error.code', 'firewall.name_taken');
    $this->postJson($this->url, [...$this->payload, 'protocol' => 'udp'])->assertConflict()->assertJsonPath('error.code', 'firewall.name_taken');
    expect(FirewallRule::query()->sole()->port)->toBe('7297');
    expect($this->socket->sent)->toHaveCount(1);
});

it('refuses non-Linux targets and unauthenticated callers before persistence', function (): void {
    $this->node->update(['platform' => 'macos']);
    $this->postJson($this->url, $this->payload)->assertUnprocessable()->assertJsonPath('error.code', 'firewall.platform_unsupported');
    $this->node->update(['platform' => 'linux']);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100']);
    $this->postJson($this->url, $this->payload)->assertForbidden();
    expect(FirewallRule::query()->count())->toBe(0);
    expect($this->socket->sent)->toBe([]);
});

it('refuses ordinary operator writes over an allowlist without changing its intent', function (string $action): void {
    $id = $this->postJson($this->url, $this->payload)->assertAccepted()->json('data.id');
    FirewallProjectionFixture::finish(Operation::query()->findOrFail($id));
    $this->postJson('/api/v1/nodes/209/firewall-rules/'.$action, ['name' => 'other', 'port' => '7296:7298', 'source' => 'any'])
        ->assertConflict()->assertJsonPath('error.code', 'firewall.allowlist_overlap');
    expect(FirewallRule::query()->count())->toBe(1);
    expect(FirewallRule::query()->sole()->sources)->toBe(['10.44.0.6', '10.44.0.9']);
    expect($this->socket->sent)->toHaveCount(1);
})->with(['allow', 'deny']);

it('refuses an allowlist over an existing operator range and permits another transport', function (): void {
    $id = $this->postJson('/api/v1/nodes/209/firewall-rules/allow', ['name' => 'other', 'port' => '7296:7298'])->assertAccepted()->json('data.id');
    FirewallProjectionFixture::finish(Operation::query()->findOrFail($id));
    $this->postJson($this->url, $this->payload)->assertConflict()->assertJsonPath('error.code', 'firewall.allowlist_overlap');
    $this->postJson($this->url, [...$this->payload, 'protocol' => 'udp'])->assertAccepted();
    expect(FirewallRule::query()->count())->toBe(2);
});

it('retains the preceding source set when another operation owns the Node firewall', function (): void {
    $id = $this->postJson($this->url, $this->payload)->assertAccepted()->json('data.id');
    $this->postJson($this->url, [...$this->payload, 'sources' => ['10.44.0.17']])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect(FirewallRule::query()->sole()->sources)->toBe(['10.44.0.6', '10.44.0.9']);
    expect($this->socket->sent)->toHaveCount(1);
    FirewallProjectionFixture::finish(Operation::query()->findOrFail($id));
    [, $doctor] = app(StartDoctorAction::class)->capture([$this->node], [DoctorFamily::Firewall], false);
    $resources = get_object_vars($doctor['nodes'][0]['resources']);
    expect($resources['firewall_rules'][0])->toMatchArray(['name' => 'rex', 'status' => 'active']);
    expect(array_column(array_column(array_filter($resources['firewall_expected'], fn (array $row): bool => $row['name'] === 'rex'), 'shape'), 'source'))->toBe(['10.44.0.6', '10.44.0.9', 'any']);
});

it('protects role-owned ports before conditional ingress rules exist', function (string $status): void {
    $cluster = Cluster::query()->create(['name' => 'edge']);
    $this->node->update(['cluster_id' => $cluster->id]);
    NodeRole::query()->create(['node_id' => $this->node->id, 'cluster_id' => $cluster->id, 'role' => 'ingress', 'status' => $status]);
    $this->postJson($this->url, [...$this->payload, 'port' => '443'])->assertUnprocessable()->assertJsonPath('error.code', 'firewall.protected_port');
    expect(FirewallRule::query()->count())->toBe(0);
    expect($this->socket->sent)->toBe([]);
})->with(['active', 'provisioning', 'failed']);
