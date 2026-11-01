<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FirewallProjectionFixture;

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-firewall-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    $this->node = Node::query()->forceCreate([
        'id' => 209, 'architecture' => 'x86_64', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key',
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

it('returns 422 for invalid labels sources protocols and ports without persistence', function (
    array $payload,
    string $field,
): void {
    $response = $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
        'label' => 'private web',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
        ...$payload,
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($response->json('error.details'))
        ->toHaveKey($field)
        ->and(FirewallRule::query()->count())
        ->toBe(0);
})->with([
    'long label' => [['label' => str_repeat('a', 256)], 'label'],
    'hostname source' => [['source' => 'example.test'], 'source'],
    'invalid CIDR' => [['source' => '192.0.2.1/33'], 'source'],
    'protocol outside tcp and udp' => [['protocol' => 'sctp'], 'protocol'],
    'zero port' => [['port' => '0'], 'port'],
    'reversed range' => [['port' => '9000:8000'], 'port'],
]);

it('refuses a deny on a port that keeps the Node reachable, before persistence', function (string $port): void {
    $this->node->update(['public_ssh_port' => 2222]);
    NodeRole::query()->create(['node_id' => $this->node->id, 'role' => 'gateway', 'status' => 'active']);
    app(VpnSettings::class)->configure('10.44.0.0/24', 51821);

    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/deny", ['port' => $port, 'source' => '192.0.2.9'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'firewall.protected_port');

    expect(FirewallRule::query()->count())->toBe(0)
        ->and(Activity::query()->where('command', 'firewall:deny')->sole()->error_code)->toBe('firewall.protected_port');
})->with(['SSH' => '22', 'recovery SSH' => '2222', 'WireGuard' => '51821', 'role port' => '440:445']);

it('admits an allow on a protected port', function (): void {
    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", ['port' => '22', 'source' => '192.0.2.9'])->assertAccepted();
});

it('stores protocol any and no label when the request omits them', function (): void {
    $id = $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", ['port' => '443'])->assertAccepted()->json('data.id');

    expect(FirewallRule::query()->sole())->protocol->toBe('any')->source->toBe('any')->label->toBeNull()
        ->and(Operation::findOrFail($id)->input['firewall']['rule'])->toMatchArray(['protocol' => 'any', 'source' => 'any', 'comment' => 'orbit', 'retain' => false]);
});

it('returns 409 before UFW for a second rule with the same identity or an overlapping protocol', function (string $action, array $payload, string $code): void {
    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", ['label' => 'web', 'protocol' => 'tcp', 'port' => '443'])->assertAccepted();

    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/{$action}", ['port' => '443', ...$payload])
        ->assertConflict()->assertJsonPath('error.code', $code);

    expect(FirewallRule::query()->sole())->action->value->toBe('allow')->label->toBe('web')->status->toBe(LifecycleStatus::Provisioning);
    $conflict = Activity::query()->where('command', "firewall:{$action}")->where('status', 'failed')->sole();
    expect($conflict)
        ->error_code->toBe($code)
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($this->node->id)
        ->target_node_id->toBe($this->node->id)
        ->and($conflict->properties?->get('path'))
        ->toBe("api/v1/nodes/{$this->node->id}/firewall-rules/{$action}");
})->with([
    'same allow' => ['allow', ['protocol' => 'tcp', 'label' => 'other'], 'firewall.rule_exists'],
    'deny on the same identity' => ['deny', ['protocol' => 'tcp'], 'firewall.rule_conflict'],
    'any over tcp' => ['allow', [], 'firewall.rule_overlap'],
]);

it('admits a second rule on the same port at a different scope', function (array $first, array $second): void {
    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/{$first[0]}", $first[1])->assertAccepted();
    FirewallProjectionFixture::finish(Operation::query()->sole());
    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/{$second[0]}", $second[1])->assertAccepted();

    expect(FirewallRule::query()->orderBy('id')->get()->map(fn (FirewallRule $rule): string => $rule->action->value.' '.$rule->identity())->all())
        ->toBe(["{$first[0]} {$first[2]}", "{$second[0]} {$second[2]}"]);
})->with([
    'deny from any, allow one source' => [['deny', ['port' => '5432'], '5432/any from any'], ['allow', ['port' => '5432', 'source' => '192.0.2.10'], '5432/any from 192.0.2.10']],
    'allow from any, deny one source' => [['allow', ['port' => '25'], '25/any from any'], ['deny', ['port' => '25', 'source' => '192.0.2.10'], '25/any from 192.0.2.10']],
    'udp beside tcp' => [['allow', ['port' => '443', 'protocol' => 'tcp'], '443/tcp from any'], ['allow', ['port' => '443', 'protocol' => 'udp'], '443/udp from any']],
]);

it('lists labelled intent and admits removal of only the selected rule by port', function (): void {
    $this->node->firewallRules()->create([
        'label' => 'private web', 'action' => 'allow', 'source' => 'any',
        'port' => '443', 'protocol' => 'tcp', 'status' => LifecycleStatus::Active,
    ]);
    $this->node->firewallRules()->create([
        'action' => 'allow', 'source' => 'any', 'port' => '8443', 'protocol' => 'tcp', 'status' => LifecycleStatus::Active,
    ]);
    $this->getJson("/api/v1/nodes/{$this->node->id}/firewall-rules")->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.label', 'private web')->assertJsonPath('data.1.label', null)
        ->assertJsonMissingPath('data.0.name')->assertJsonMissingPath('data.0.backend_status');
    $id = $this->withHeader('Idempotency-Key', 'remove-proof')->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/remove", ['port' => '443'])
        ->assertAccepted()->assertJsonPath('data.kind', 'firewall.remove')->json('data.id');
    expect(Operation::findOrFail($id)->input)->toMatchArray(['label' => 'private web', 'port' => '443'])->not->toHaveKey('name')
        ->and(FirewallRule::query()->where('port', '443')->sole()->status)->toBe(LifecycleStatus::Removing);
    FirewallProjectionFixture::finish(Operation::findOrFail($id));
    expect(FirewallRule::query()->pluck('port')->all())->toBe(['8443']);
    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/remove", ['port' => '443'])->assertAccepted()->assertJsonPath('data.id', $id);
});

it('refuses an ambiguous removal with the matches and narrows it by protocol or source', function (): void {
    $this->node->firewallRules()->create(['label' => 'web', 'action' => 'allow', 'source' => 'any', 'port' => '443', 'protocol' => 'tcp', 'status' => LifecycleStatus::Active]);
    $this->node->firewallRules()->create(['action' => 'deny', 'source' => '192.0.2.9', 'port' => '443', 'protocol' => 'udp', 'status' => LifecycleStatus::Active]);

    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/remove", ['port' => '443'])
        ->assertConflict()->assertJsonPath('error.code', 'firewall.rule_ambiguous')
        ->assertJsonPath('error.details.matches', [
            ['port' => '443', 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'label' => 'web'],
            ['port' => '443', 'protocol' => 'udp', 'source' => '192.0.2.9', 'action' => 'deny', 'label' => null],
        ]);
    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/remove", ['port' => '443', 'protocol' => 'any'])
        ->assertNotFound()->assertJsonPath('error.code', 'firewall.rule_not_found');
    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/remove", ['port' => '443', 'source' => '192.0.2.9/32'])
        ->assertAccepted()->assertJsonPath('data.kind', 'firewall.remove');

    expect(FirewallRule::query()->where('status', LifecycleStatus::Removing)->sole()->protocol)->toBe('udp');
});

it('returns 404 without exposing a rule that belongs to another node', function (): void {
    $other = Node::query()->create([
        'name' => 'other',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.4',
    ]);
    $otherRule = $other->firewallRules()->create([
        'action' => 'allow', 'source' => 'any', 'protocol' => 'tcp', 'port' => '443', 'status' => LifecycleStatus::Active,
    ]);

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/remove", ['port' => '443'])
        ->assertNotFound()->assertJsonPath('error.code', 'firewall.rule_not_found');

    $requestedRule = $this->node->firewallRules()->create([
        'action' => 'allow', 'source' => 'any', 'protocol' => 'tcp', 'port' => '443', 'status' => LifecycleStatus::Active,
    ]);
    $id = $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/remove", ['port' => '443'])->assertAccepted()->json('data.id');

    FirewallProjectionFixture::finish(Operation::findOrFail($id));
    $this->assertDatabaseHas('firewall_rules', ['id' => $otherRule->id]);
    $this->assertDatabaseMissing('firewall_rules', ['id' => $requestedRule->id]);
});
