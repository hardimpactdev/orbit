<?php

declare(strict_types=1);

use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\FirewallRule;
use App\Models\Node;

beforeEach(function (): void {
    $this->node = Node::query()->create([
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

it('records inactive UFW as an Operation failure and retains failed intent', function (): void {
    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
        'name' => 'private-web', 'source' => '192.0.2.129/24', 'protocol' => 'tcp', 'port' => '0443',
    ])->assertAccepted();
    $executor = app(SwampOperationExecutor::class);
    $operation = $executor->claim('firewall.allow', 'test');
    $executor->step($operation, ['worker' => 'test', 'key' => 'failure', 'name' => 'ufw-converge', 'status' => 'failed', 'error_code' => 'firewall.backend_inactive']);
    $executor->complete($operation, ['worker' => 'test', 'status' => 'failed', 'error_code' => 'firewall.backend_inactive']);
    expect(FirewallRule::query()->sole())->status->toBe(LifecycleStatus::Failed)
        ->error_code->toBe('firewall.backend_inactive')->failed_step->toBe('ufw-converge')->source->toBe('192.0.2.0/24')->port->toBe('443');
});

it('returns 422 for invalid names sources protocols and ports without persistence', function (
    array $payload,
    string $field,
): void {
    $response = $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
        'name' => 'private-web',
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
    'unsafe name' => [['name' => 'Private Web'], 'name'],
    'hostname source' => [['source' => 'example.test'], 'source'],
    'invalid CIDR' => [['source' => '192.0.2.1/33'], 'source'],
    'protocol outside tcp and udp' => [['protocol' => 'sctp'], 'protocol'],
    'zero port' => [['port' => '0'], 'port'],
    'reversed range' => [['port' => '9000:8000'], 'port'],
]);

it('returns 422 before persistence when a deny intersects public recovery SSH', function (): void {
    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/deny", [
            'name' => 'block-ssh',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '1:1024',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'firewall.public_ssh_deny_forbidden');

    expect(FirewallRule::query()->count())->toBe(0);

    $activity = Activity::query()->where('command', 'firewall:deny')->sole();

    expect($activity)
        ->status->toBe('failed')
        ->error_code->toBe('firewall.public_ssh_deny_forbidden')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($this->node->id)
        ->target_node_id->toBe($this->node->id);
});

it('returns 409 before UFW when allow and deny share a node source protocol and port', function (): void {
    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
            'name' => 'private-web',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
        ])
        ->assertAccepted();

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/deny", [
            'name' => 'block-web',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'firewall.action_conflict');

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
            'name' => 'private-web',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'operation.target_busy');

    $this->assertDatabaseHas('firewall_rules', [
        'node_id' => $this->node->id,
        'name' => 'private-web',
        'action' => 'allow',
        'status' => 'provisioning',
        'failed_step' => null,
        'error_code' => null,
    ]);
    $this->assertDatabaseMissing('firewall_rules', [
        'node_id' => $this->node->id,
        'name' => 'block-web',
    ]);

    $conflict = Activity::query()
        ->where('command', 'firewall:deny')
        ->where('status', 'failed')
        ->sole();

    expect($conflict)
        ->error_code->toBe('firewall.action_conflict')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($this->node->id)
        ->target_node_id->toBe($this->node->id)
        ->and($conflict->properties?->get('path'))
        ->toBe("api/v1/nodes/{$this->node->id}/firewall-rules/deny");
});

it('lists stable named intent and admits removal of only the selected rule', function (): void {
    $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
        'name' => 'private-web', 'port' => '443',
    ])->assertAccepted();
    $executor = app(SwampOperationExecutor::class);
    $executor->complete($executor->claim('firewall.allow', 'test'), ['worker' => 'test', 'status' => 'succeeded']);
    $this->getJson("/api/v1/nodes/{$this->node->id}/firewall-rules")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'private-web')->assertJsonMissingPath('data.0.backend_status');
    $this->deleteJson("/api/v1/nodes/{$this->node->id}/firewall-rules/private-web")->assertAccepted()->assertJsonPath('data.kind', 'firewall.remove');
    expect(FirewallRule::query()->sole()->status)->toBe(LifecycleStatus::Removing);
    $executor->complete($executor->claim('firewall.remove', 'remove-test'), ['worker' => 'remove-test', 'status' => 'succeeded']);
    expect(FirewallRule::query()->count())->toBe(0);
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
    $other
        ->firewallRules()
        ->create([
            'name' => 'private-web',
            'action' => 'allow',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
            'status' => LifecycleStatus::Active,
        ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/firewall-rules/private-web")
        ->assertNotFound();

});

it('binds identical firewall rule names through the requested node', function (): void {
    $other = Node::query()->create([
        'name' => 'other-identical-name',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.22',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.5',
    ]);
    $otherRule = $other
        ->firewallRules()
        ->create([
            'name' => 'private-web',
            'action' => 'allow',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
            'status' => LifecycleStatus::Active,
        ]);
    $requestedRule = $this->node
        ->firewallRules()
        ->create([
            'name' => 'private-web',
            'action' => 'allow',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
            'status' => LifecycleStatus::Active,
        ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/firewall-rules/private-web")
        ->assertAccepted();

    $executor = app(SwampOperationExecutor::class);
    $executor->complete($executor->claim('firewall.remove', 'test'), ['worker' => 'test', 'status' => 'succeeded']);
    $this->assertDatabaseHas('firewall_rules', ['id' => $otherRule->id]);
    $this->assertDatabaseMissing('firewall_rules', ['id' => $requestedRule->id]);

});
