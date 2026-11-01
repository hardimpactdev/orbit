<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAction;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

it('admits one209 removal with complete immutable public intent and no host execution', function (): void {
    FleetFixtures::bind();
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 fixture-bootstrap-public');
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
    config(['swamp.run_token' => 'fixture.fake']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $caller = $this->markAsGateway(FleetFixtures::node('removal-http-caller'));
    $node = FleetFixtures::node('removal-http-node');
    $node->update(['wireguard_public_key' => null]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson('/api/v1/nodes/'.$node->id, ['offline' => true, 'force' => true])
        ->assertAccepted()->assertJsonPath('data.kind', 'node.remove')->assertJsonPath('data.target_id', $node->id);

    expect(Operation::query()->count())->toBe(1);
    expect($socket->sent)->toHaveCount(1);
    $start = $socket->sent[0]['payload'];
    expect($start['workflowIdOrName'])->toBe('operation-node-remove');
    expect($start['inputs']['intent'])->toMatchArray(['node_id' => $node->id, 'name' => $node->name, 'offline' => true]);
    expect($start['inputs']['intent']['probe']['host'])->toBe($node->wireguard_ip);
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->input)->not->toHaveKeys(['private_bindings', 'bootstrap_identity', '_removal']);
    $this->assertModelExists($node);
    expect($node->fresh()->status)->toBe(LifecycleStatus::Removing);
});

it('refuses protected Nodes before admission', function (RoleName $role): void {
    $caller = FleetFixtures::node('removal-direct-caller');
    $node = FleetFixtures::node('removal-direct-protected', [$role]);

    expect(fn () => app(RemoveNodeAction::class)->start($node, $caller, true, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.'.$role->value.'_removal_forbidden'));
    expect(Operation::query()->count())->toBe(0);
    $this->assertModelExists($node);
})->with([RoleName::Gateway, RoleName::Vpn]);

it('protects209 even when its role list is empty', function (): void {
    $caller = FleetFixtures::node('removal-control-caller');
    $node = FleetFixtures::node('fixture-control');
    $node->id = 209;
    $node->exists = true;

    expect(fn () => app(RemoveNodeAction::class)->start($node, $caller, true, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.control_removal_forbidden'));
    expect(Operation::query()->count())->toBe(0);
});

it('requires force confirmation for offline removal and role shedding', function (bool $offline, array $roles): void {
    $caller = FleetFixtures::node('removal-consent-caller');
    $node = FleetFixtures::node('removal-consent-node', $roles);

    expect(fn () => app(RemoveNodeAction::class)->start($node, $caller, $offline))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.confirmation_required'));
    expect(Operation::query()->count())->toBe(0);
})->with([[true, []], [false, [RoleName::Database]]]);

it('keeps TaskVM deletion with its owning lifecycle', function (bool $deleted): void {
    $caller = FleetFixtures::node('removal-vm-caller');
    $node = FleetFixtures::node('removal-vm-node');
    if (! $deleted) {
        TaskVm::query()->create(['node_id' => $node->id, 'name' => $node->name, 'wireguard_ip' => $node->wireguard_ip, 'provider' => 'incus', 'state' => 'destroying']);
    }

    expect(fn () => app(RemoveNodeAction::class)->start($node, $caller, true, true, $deleted))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.removal_owner_required'));
    expect(Operation::query()->count())->toBe(0);
})->with([false, true]);

it('captures canonical role children with stored WireGuard pins and immutable intent', function (): void {
    FleetFixtures::bind();
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 fixture-bootstrap-public');
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);
    $node = FleetFixtures::node('removal-role-child', [RoleName::Database]);
    $node->update(['wireguard_public_key' => null]);
    $capture = app(NodeRemovalInput::class)->capture(app(NodeRemovalPlan::class)->build($node, false));

    expect($capture['intent']['roles'])->toHaveCount(1);
    expect($capture['intent']['roles'][0])->toMatchArray(['node_id' => $node->id, 'role' => 'database']);
    expect($capture['intent']['roles'][0]['ssh']['target']['host'])->toBe($node->wireguard_ip);
    expect($capture['intent']['roles'][0]['ssh']['target']['host_keys'])->toBe([$node->ssh_host_key_type.' '.$node->ssh_host_key]);
    $operation = new Operation(['kind' => 'node.remove', 'input' => ['intent' => $capture['intent']]]);
    $node->update(['user' => 'changed-after-admission']);
    expect(app(NodeRemovalProjection::class)->input($operation)['intent'])->toBe($capture['intent']);
    expect(app(DirectOperation::class)->definition('node.remove'))->not->toHaveKey('private_material');
});

it('deletes Gateway records only after successful native terminal output', function (string $status): void {
    FleetFixtures::bind();
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 fixture-bootstrap-public');
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);
    $node = FleetFixtures::node('removal-terminal-'.$status);
    $snapshot = app(NodeRemovalPlan::class)->build($node, true);
    $run = '3a332a4f-0911-4e9d-9a18-09899b7b1864';
    $operation = Operation::query()->create(['kind' => 'node.remove', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id,
        'status' => 'running', 'sequence' => 1, 'state' => ['swamp_run_id' => $run], 'input' => ['_node_removal' => $snapshot, 'intent' => []]]);

    app(NodeRemovalProjection::class)->apply($operation, ['run_id' => $run, 'status' => $status,
        'started_at' => '2026-11-01T04:00:00.000Z', 'finished_at' => '2026-11-01T04:00:01.000Z',
        'result' => $status === 'succeeded' ? ['schema_version' => 1, 'node_id' => $node->id, 'removed' => true] : [],
        'error_code' => $status === 'succeeded' ? null : 'node.remove_failed']);

    if ($status === 'succeeded') {
        $this->assertModelMissing($node);
    } else {
        $this->assertModelExists($node);
        expect($node->fresh()->status)->toBe(LifecycleStatus::Failed);
    }
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('refuses an active role claim before changing membership', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('removal-claimed-role', [RoleName::Database]);
    $node->roles()->update(['status' => LifecycleStatus::Provisioning]);
    expect(fn () => app(NodeRemovalInput::class)->capture(app(NodeRemovalPlan::class)->build($node, false)))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.role_busy'));
    expect($node->fresh()->status)->toBe(LifecycleStatus::Active);
    expect($node->roles()->sole()->status)->toBe(LifecycleStatus::Provisioning);
});

it('captures Metrics teardown for the canonical surviving fleet including209', function (): void {
    FleetFixtures::bind();
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 fixture-bootstrap-public');
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);
    $this->markAsGateway(FleetFixtures::node('removal-metrics-gateway'));
    $control = FleetFixtures::node('removal-control-exporter');
    Node::query()->whereKey($control->id)->update(['id' => 209]);
    app(ExporterPreferenceRepository::class)->put(209, ExporterPreference::Enabled);
    $roleless = FleetFixtures::node('removal-roleless-unpinned');
    $roleless->update(['ssh_host_key_type' => null, 'ssh_host_key' => null]);
    $metrics = FleetFixtures::node('removal-metrics', [RoleName::Metrics]);
    $metrics->update(['wireguard_public_key' => null]);
    $capture = app(NodeRemovalInput::class)->capture(app(NodeRemovalPlan::class)->build($metrics, false));
    $role = $capture['intent']['roles'][0];
    expect(array_column($role['remove_footprints'], 'input'))->toContain(['node_id' => 209, 'group' => 'exporter']);
    expect($role['certificates'])->not->toBeEmpty();
    expect($role['publications'])->not->toBeEmpty();
    expect(array_column($role['firewall'], 'input'))->not->toBeEmpty();
    expect($capture['intent']['preserved_on_node'])->not->toBeEmpty();
});
