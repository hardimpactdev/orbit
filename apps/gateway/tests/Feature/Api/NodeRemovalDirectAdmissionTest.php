<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAction;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalProjection;
use App\Domain\Nodes\NodeRemovalRecords;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NodeBootstrapSshIdentitySource;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\TaskVm;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

it('copies only the existing retirement identity into the shared vault and records names only', function (bool $refused): void {
    FleetFixtures::bind();
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-start-token', 'swamp.ca_path' => '/public/root.pem']);
    $caller = $this->markAsGateway(FleetFixtures::node('removal-copy-root'));
    $node = FleetFixtures::node('removal-copy-target');
    $value = 'synthetic-retirement-'.bin2hex(random_bytes(32));
    $path = tempnam(sys_get_temp_dir(), 'removal-copy-');
    File::put($path, $value);
    $this->beforeApplicationDestroyed(static fn (): bool => unlink($path));
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->once()->andReturn($path);
    app()->instance(SshKeyProvider::class, $keys);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (ProcessInvocation $invocation) use ($refused, $value, $caller): CommandResult {
        expect(stream_get_contents($invocation->protectedInput->stream()))->toBe($value);
        expect($invocation->arguments)->toContain('vault', 'put', 'orbit-control-inputs', 'bootstrap-identity-node-'.$caller->id);
        expect(json_encode($invocation->arguments, JSON_THROW_ON_ERROR))->not->toContain($value);

        return new CommandResult($refused ? 1 : 0, '{}', '', 1, false);
    });
    app()->instance(ProcessRunner::class, $runner);

    if ($refused) {
        expect(fn () => app(RemoveNodeAction::class)->start($node, $caller))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('swamp.vault_sync_unavailable'));
        expect($socket->sent)->toBeEmpty();
        expect(Operation::query()->count())->toBe(0);
        expect($node->fresh()->status)->toBe(LifecycleStatus::Active);

        return;
    }

    $operation = app(RemoveNodeAction::class)->start($node, $caller);
    $references = ['bootstrap_identity' => ['vault' => 'orbit-control-inputs', 'key' => 'bootstrap-identity-node-'.$caller->id]];
    expect($operation->input['execution_secrets'])->toBe($references);
    expect(app(NodeRemovalProjection::class)->input($operation))->toBe([
        'intent' => $operation->input['_removal'], 'execution_secrets' => $references,
    ]);
    expect(json_encode($operation->input, JSON_THROW_ON_ERROR))->not->toContain($value)->not->toContain($path);
    expect($socket->sent)->toHaveCount(1);
})->with([false, true]);

it('records one native removal intent through the public endpoint without executing cleanup', function (): void {
    FleetFixtures::bind();
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
    config(['swamp.run_token' => 'fixture.fake']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $caller = $this->markAsGateway(FleetFixtures::node('removal-http-caller'));
    $node = FleetFixtures::node('removal-http-node');
    $direct = app(DirectOperation::class);
    if ($direct->definition('node.remove') === null) {
        $contribution = new readonly class implements DirectOperationContribution
        {
            public function definitions(): array
            {
                return ['node.remove' => ['workflow' => 'operation-node-remove', 'projection' => NodeRemovalProjection::class,
                    'private_material' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity']]];
            }
        };
        app()->instance(DirectOperation::class, new DirectOperation([$contribution]));
    }
    $response = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson('/api/v1/nodes/'.$node->id, ['offline' => true, 'force' => true])
        ->assertAccepted()
        ->assertJsonPath('data.kind', 'node.remove')
        ->assertJsonPath('data.target_id', $node->id)
        ->assertJsonPath('data.attempts', 0);
    expect(Operation::query()->count())->toBe(1);
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-node-remove');
    expect(Operation::query()->findOrFail($response->json('data.id'))->node_id)->toBe($caller->id);
    $this->assertModelExists($node);
});

it('refuses protected Node deletion without admitting or executing an Operation', function (RoleName $role): void {
    $caller = FleetFixtures::node('removal-direct-caller');
    $node = FleetFixtures::node('removal-direct-protected', [$role]);
    expect(fn () => app(RemoveNodeAction::class)->start($node, $caller, true, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.'.$role->value.'_removal_forbidden'));
    expect(Operation::query()->count())->toBe(0);
    $this->assertModelExists($node);
})->with([RoleName::Gateway, RoleName::Vpn]);

it('requires explicit offline confirmation before recording intent', function (): void {
    $caller = FleetFixtures::node('removal-direct-confirm-caller');
    $node = FleetFixtures::node('removal-direct-confirm-node');
    expect(fn () => app(RemoveNodeAction::class)->start($node, $caller, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.confirmation_required'));
    expect(Operation::query()->count())->toBe(0);
});

it('does not accept a provider-deleted assertion through standalone removal admission', function (): void {
    $caller = FleetFixtures::node('removal-direct-deleted-caller');
    $node = FleetFixtures::node('removal-direct-deleted-node');
    expect(fn () => app(RemoveNodeAction::class)->start($node, $caller, true, true, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.removal_owner_required'));
    expect(Operation::query()->count())->toBe(0);
});

it('requires the owning TaskVM native root rather than a nested node.remove intent', function (): void {
    $caller = FleetFixtures::node('removal-direct-vm-caller');
    $node = FleetFixtures::node('removal-direct-vm-node', [RoleName::AppDev]);
    TaskVm::query()->create(['node_id' => $node->id, 'name' => $node->name, 'wireguard_ip' => $node->wireguard_ip, 'provider' => 'incus', 'state' => 'destroying']);
    expect(fn () => app(RemoveNodeAction::class)->start($node, $caller, true, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.removal_owner_required'));
    expect(Operation::query()->count())->toBe(0);
    $this->assertDatabaseHas('node_roles', ['node_id' => $node->id, 'role' => 'app-dev']);
});

it('binds off-worker retirement identity only to the exact Gateway root and pinned guest', function (string $drift): void {
    FleetFixtures::bind();
    $root = FleetFixtures::node('removal-identity-root', [RoleName::Gateway]);
    $guest = FleetFixtures::node('removal-identity-guest');
    app()->instance(ManagedUserAccountResolver::class, new readonly class($guest) implements ManagedUserAccountResolver
    {
        public function __construct(private Node $guest) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            if (! $node->is($this->guest)) {
                throw new RuntimeException('A foreign account was requested.');
            }

            return new ManagedUserAccount($node->user, 'nodegroup', '/srv/managed-node');
        }
    });
    $direct = app(DirectOperation::class);
    if (! isset($direct->definition('node.remove')['private_material']['bootstrap_identity'])) {
        app()->instance(DirectOperation::class, new DirectOperation([new readonly class implements DirectOperationContribution
        {
            public function definitions(): array
            {
                return ['node.remove' => ['workflow' => 'operation-node-remove', 'projection' => NodeRemovalProjection::class,
                    'private_material' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity']]];
            }
        }]));
    }
    $snapshot = app(NodeRemovalPlan::class)->build($guest, false);
    $execution = app(NodeRemovalInput::class)->capture($snapshot, executor: $root);
    $input = ['_node_removal' => $execution['snapshot'], '_removal' => $execution, 'participant_node_ids' => [$root->id, $guest->id],
        'bootstrap_identity' => ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $root->id],
        'private_bindings' => ['bootstrap_identity' => ['owner_node_id' => $root->id, 'read_node_ids' => [$root->id], 'write_node_ids' => []]]];
    $operation = new Operation(['kind' => 'node.remove', 'node_id' => $root->id, 'target_type' => 'node', 'target_id' => $guest->id,
        'status' => 'running', 'input' => $input]);
    expect(NodeRemovalInput::privateOwner($operation))->toBe($root->id);
    expect(app(NodeBootstrapSshIdentitySource::class)->ownerId($operation))->toBe($root->id);
    match ($drift) {
        'root' => $operation->node_id = $guest->id,
        'guest' => $operation->target_id = $root->id,
        'host' => $input['_removal']['retirement']['target']['host'] = '203.0.113.199',
        'fingerprint' => $input['_removal']['retirement']['target']['fingerprint'] = 'SHA256:'.str_repeat('Z', 43),
        'reader' => $input['private_bindings']['bootstrap_identity']['read_node_ids'] = [$guest->id],
        'participant' => $input['participant_node_ids'] = [$root->id],
        'reference' => $input['bootstrap_identity']['id'] = $guest->id,
        'terminal' => $operation->status = OperationStatus::Succeeded,
    };
    $operation->input = $input;
    expect(NodeRemovalInput::privateOwner($operation))->toBeNull();
    expect(app(NodeBootstrapSshIdentitySource::class)->ownerId($operation))->toBeNull();
})->with(['root', 'guest', 'host', 'fingerprint', 'reader', 'participant', 'reference', 'terminal']);

it('freezes and withdraws actual offline Route rows without asking the absent guest for an account', function (): void {
    FleetFixtures::bind();
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
    config(['swamp.run_token' => 'fixture.fake']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    $root = FleetFixtures::node('removal-route-root', [RoleName::Gateway]);
    $guest = FleetFixtures::node('removal-route-offline');
    $project = Project::query()->create(['name' => 'Removal route', 'slug' => 'removal-route', 'repository_url' => 'https://github.com/example/removal.git']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'domain' => 'remove-offline.orbit',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    if (app(DirectOperation::class)->definition('node.remove') === null) {
        app()->instance(DirectOperation::class, new DirectOperation([new readonly class implements DirectOperationContribution
        {
            public function definitions(): array
            {
                return ['node.remove' => ['workflow' => 'operation-node-remove', 'projection' => NodeRemovalProjection::class,
                    'private_material' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity']]];
            }
        }]));
    }
    $operation = app(RemoveNodeAction::class)->start($guest, $root, true, true);
    expect($operation->input['_removal']['routes']['routes'])->toHaveCount(1);
    expect($operation->input['_removal']['routes']['routes'][0]['route_id'])->toBe($route->id);
    expect($operation->input['_removal']['routes']['excluded_effect_node_ids'])->toBe([$guest->id]);
    expect($operation->input['_removal']['routes']['nodes'])->toBe([]);
    expect($route->fresh()->status)->toBe(RouteStatus::Retiring);
    $this->assertModelExists($route);
    $this->assertModelExists($guest);
    app(NodeRemovalRecords::class)->restore($operation->input['_node_removal'], $operation->input['route_prior_states']);
    expect($route->fresh()->status)->toBe(RouteStatus::Pending);
});

it('checks native binding and terminal conflicts before changing Node membership or deleting rows', function (string $case): void {
    FleetFixtures::bind();
    if (app(DirectOperation::class)->definition('node.remove') === null) {
        app()->instance(DirectOperation::class, new DirectOperation([new readonly class implements DirectOperationContribution
        {
            public function definitions(): array
            {
                return ['node.remove' => ['workflow' => 'operation-node-remove', 'projection' => NodeRemovalProjection::class]];
            }
        }]));
    }
    $node = FleetFixtures::node('removal-projection-binding');
    $run = '3a332a4f-0911-4e9d-9a18-09899b7b1864';
    $snapshot = app(NodeRemovalPlan::class)->build($node, true);
    $operation = Operation::query()->create(['kind' => 'node.remove', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id,
        'status' => 'running', 'sequence' => 1, 'state' => ['swamp_run_id' => $run],
        'input' => ['_node_removal' => $snapshot, '_removal' => ['routes' => ['routes' => [], 'nodes' => [], 'dns_node_ids' => []]]]]);
    $data = ['run_id' => $run, 'status' => 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z',
        'result' => ['schema_version' => 1, 'node_id' => $node->id, 'removed' => true]];
    if ($case === 'foreign-run') {
        $data['run_id'] = 'ff3b7992-7376-4ac7-a49f-ed45bedc8de5';
        $data['status'] = 'running';
        $data['result'] = ['removing' => true];
    } else {
        $operation->update(['status' => OperationStatus::Cancelled, 'result' => []]);
    }
    expect(fn () => app(NodeRemovalProjection::class)->apply($operation, $data))->toThrow(ResourceOperationException::class);
    $this->assertModelExists($node);
    expect($node->fresh()->status)->toBe(LifecycleStatus::Active);
})->with(['foreign-run', 'terminal-conflict']);

it('preserves a required DNS phase when Route capture finds an existing participant', function (): void {
    FleetFixtures::bind();
    $root = FleetFixtures::node('removal-dns-root', [RoleName::Gateway]);
    $dns = FleetFixtures::node('removal-dns-owner', [RoleName::Vpn]);
    $guest = FleetFixtures::node('removal-dns-guest');
    $project = Project::query()->create(['name' => 'DNS cleanup', 'slug' => 'removal-dns-cleanup', 'repository_url' => 'https://github.com/example/removal.git']);
    Route::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'domain' => 'removal-dns.orbit',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $snapshot = app(NodeRemovalPlan::class)->build($guest, true);
    $snapshot['participants'] = array_map(static fn (array $row): array => [...$row, 'dns' => false], $snapshot['participants']);
    $capture = app(NodeRemovalInput::class)->capture($snapshot, executor: $root);
    $member = array_find($capture['snapshot']['participants'], static fn (array $row): bool => $row['node_id'] === $dns->id);
    expect($member['dns'])->toBeTrue();
    expect(array_column($capture['snapshot']['participants'], 'node_id'))->toHaveCount(count($snapshot['participants']));
});

it('keeps active Route reconciliation mandatory even for forced offline removal', function (): void {
    FleetFixtures::bind();
    $root = FleetFixtures::node('removal-active-route-root', [RoleName::Gateway]);
    $guest = FleetFixtures::node('removal-active-route-guest');
    $route = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $guest->id, 'domain' => 'removal-active.orbit',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->customProxy()->create(['node_id' => $guest->id, 'upstream' => 'http://127.0.0.1:8080']);
    $route->update(['status' => 'active']);
    expect(fn () => app(RemoveNodeAction::class)->start($guest, $root, true, true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('route.reconciliation_required'));
    expect(Operation::query()->count())->toBe(0);
    $this->assertModelExists($guest);
});

it('binds withdrawal and restoration hub material to the admitted Node removal root', function (string $change): void {
    FleetFixtures::bind();
    $root = FleetFixtures::node('removal-bound-root', [RoleName::Gateway]);
    $hub = FleetFixtures::node('removal-bound-hub', [RoleName::Vpn]);
    $node = FleetFixtures::node('removal-bound-node');
    $snapshot = app(NodeRemovalPlan::class)->build($node, true);
    $wire = [...app(NodeWireGuardInput::class)->capture($node, $node->id), 'executor_node_id' => $root->id];
    $restore = [...$wire, 'excluded_node_id' => null];
    $operation = new Operation(['kind' => 'node.remove', 'node_id' => $root->id, 'target_type' => 'node', 'target_id' => $node->id,
        'status' => 'running', 'input' => ['_node_removal' => $snapshot, '_removal' => ['snapshot' => $snapshot, 'hub' => $wire, 'restore_hub' => $restore],
            '_wireguard' => $wire, '_wireguard_restore' => $restore, 'participant_node_ids' => [$root->id, $hub->id, $node->id],
            'private_bindings' => ['configuration' => ['owner_node_id' => $hub->id, 'read_node_ids' => [$root->id], 'write_node_ids' => []]]]]);
    expect(NodeWireGuardInput::bound($operation))->toBe($wire);
    $operation->result = ['restoring' => true];
    expect(NodeWireGuardInput::bound($operation))->toBe($wire);
    $operation->state = ['node_wireguard_restoring' => true];
    expect(NodeWireGuardInput::bound($operation))->toBe($restore);
    $input = $operation->input;
    match ($change) {
        'root' => $operation->node_id = $node->id,
        'reader' => $input['private_bindings']['configuration']['read_node_ids'] = [$node->id],
        'restore snapshot' => $input['_wireguard_restore']['context_node_id'] = $hub->id,
        'admitted snapshot' => $input['_removal']['snapshot']['name'] = 'changed',
    };
    $operation->input = $input;
    expect(NodeWireGuardInput::bound($operation))->toBeNull();
})->with(['root', 'reader', 'restore snapshot', 'admitted snapshot']);

it('captures the online guard refusal for Swamp to apply when an offline target answers', function (): void {
    FleetFixtures::bind();
    $root = FleetFixtures::node('removal-reachable-root', [RoleName::Gateway]);
    $node = FleetFixtures::node('removal-reachable-role', [RoleName::AppDev]);
    $capture = app(NodeRemovalInput::class)->capture(app(NodeRemovalPlan::class)->build($node, true), executor: $root);
    expect($capture['online_blocker'])->toBe('node.has_roles');
    expect($node->fresh()->status)->toBe(LifecycleStatus::Active);
    expect($node->roles()->count())->toBe(1);
});

it('restores a failed Node to failed after a native compensation result', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('removal-failed-prior', status: LifecycleStatus::Failed);
    $snapshot = app(NodeRemovalPlan::class)->build($node, true);
    app(NodeRemovalRecords::class)->begin($snapshot);
    app(NodeRemovalRecords::class)->restore($snapshot);
    expect($node->fresh()->status)->toBe(LifecycleStatus::Failed);
});
