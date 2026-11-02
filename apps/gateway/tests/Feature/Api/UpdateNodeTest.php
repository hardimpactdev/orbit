<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\UpdateNodeRequest;

describe('PATCH /api/v1/nodes/{node} with a platform', function (): void {
    beforeEach(function (): void {
        $this->markAsGateway(Node::query()->create([
            'name' => 'gateway', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
            'ssh_host_fingerprint' => 'SHA256:'.str_repeat('G', 43),
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
        $this->peer = Node::query()->create([
            'name' => 'ipad', 'status' => LifecycleStatus::Active,
            'architecture' => 'arm64', 'public_ssh_host' => '10.44.0.12', 'user' => 'ipad',
            'wireguard_ip' => '10.44.0.12', 'wireguard_public_key' => base64_encode(str_repeat('i', 32)),
        ]);
        $this->endpoint = '/api/v1/nodes/'.$this->peer->id;
    });

    it('sets an unmanaged peer to ipados, broadcasts the update, and records Activity', function (): void {
        Event::fake([RecordBroadcast::class]);
        $before = $this->peer->getAttributes();

        $response = $this->patchJson($this->endpoint, ['platform' => 'ipados'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->peer->id)
            ->assertJsonPath('data.platform', 'ipados')
            ->assertJsonPath('data.architecture', 'arm64')
            ->assertJsonPath('data.ssh_host_fingerprint', null)
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('meta.request_id', fixture_request_id());
        record_fixture($response, 'nodes/node-update/platform-updated', UpdateNodeRequest::class, 'PATCH /api/v1/nodes/{node}');

        expect(array_diff_assoc($this->peer->refresh()->getAttributes(), $before))->toHaveKeys(['platform'])
            ->and($this->peer->platform)->toBe('ipados')
            ->and($this->peer->wireguard_public_key)->toBe(base64_encode(str_repeat('i', 32)));
        Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeUpdated
            && $event->id === $this->peer->id && $event->data['platform'] === 'ipados');
        $activity = Activity::query()->where('request_id', fixture_request_id())->sole();
        expect($activity->command)->toBe('node:update')
            ->and($activity->target_node_id)->toBe($this->peer->id);
    });

    it('writes nothing when the peer already has the platform', function (): void {
        $this->peer->update(['platform' => 'ipados']);
        Event::fake([RecordBroadcast::class]);

        $this->patchJson($this->endpoint, ['platform' => 'ipados'])->assertOk()->assertJsonPath('data.platform', 'ipados');

        Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeUpdated);
    });

    it('sets an ipados peer back to linux', function (): void {
        $this->peer->update(['platform' => 'ipados']);

        $this->patchJson($this->endpoint, ['platform' => 'linux'])->assertOk()->assertJsonPath('data.platform', 'linux');
    });

    it('refuses a Node with a pinned SSH host key', function (): void {
        $this->peer->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('P', 43)]);

        $response = $this->patchJson($this->endpoint, ['platform' => 'ipados'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'node.platform_managed');
        record_fixture($response, 'nodes/node-update/platform-managed-refused', UpdateNodeRequest::class, 'PATCH /api/v1/nodes/{node}');

        expect($this->peer->refresh()->platform)->toBe('linux');
    });

    it('refuses a Node with a role', function (): void {
        $this->peer->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Failed]);

        $this->patchJson($this->endpoint, ['platform' => 'ipados'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'node.platform_managed');

        expect($this->peer->refresh()->platform)->toBe('linux');
    });

    it('rejects an unknown platform, a list body, and extra members', function (array $body): void {
        Event::fake([RecordBroadcast::class]);

        $this->patchJson($this->endpoint, $body)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($this->peer->refresh()->platform)->toBe('linux');
        Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeUpdated);
    })->with([
        'unknown' => [['platform' => 'android']],
        'list body' => [[]],
        'extra' => [['platform' => 'ipados', 'architecture' => 'arm64']],
    ]);

    it('refuses while another lifecycle operation holds the Node name', function (): void {
        $lock = app(NativeNodeProvisioningLock::class);

        $lock->run('ipad', function (): void {
            $this->patchJson($this->endpoint, ['platform' => 'ipados'])
                ->assertConflict()
                ->assertJsonPath('error.code', 'node.provisioning_busy');
        });

        expect($this->peer->refresh()->platform)->toBe('linux');
    });

    it('refuses a body with no member before it changes anything', function (string $content): void {
        $this->call('PATCH', $this->endpoint, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $content)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($this->peer->refresh()->platform)->toBe('linux');
    })->with(['empty object' => '{}', 'empty body' => '']);

    it('refuses a platform on a managed Node and leaves the name in the same request unsaved', function (): void {
        $this->peer->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('P', 43)]);

        $this->patchJson($this->endpoint, ['name' => 'tablet', 'platform' => 'ipados'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'node.platform_managed');

        expect($this->peer->refresh())->platform->toBe('linux')->name->toBe('ipad');
    });
});

describe('PATCH /api/v1/nodes/{node} with a name', function (): void {
    beforeEach(function (): void {
        $this->operator = $this->markAsGateway(Node::query()->create([
            'name' => 'operator',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.2',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $this->node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
            'wireguard_public_key' => 'wg-gateway-public',
        ]);
    });

    it('renames a node and leaves wireguard identity and roles in place', function (): void {
        $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $this->node->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
        $requestId = (string) Str::uuid();

        $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->patchJson("/api/v1/nodes/{$this->node->id}", ['name' => 'vpn'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->node->id)
            ->assertJsonPath('data.name', 'vpn')
            ->assertJsonPath('data.wireguard_ip', '10.44.0.1')
            ->assertJsonPath('data.wireguard_public_key', 'wg-gateway-public')
            ->assertJsonPath('data.roles', ['gateway', 'vpn'])
            ->assertJsonPath('meta.request_id', $requestId);

        expect($this->node->refresh())
            ->name->toBe('vpn')
            ->wireguard_ip->toBe('10.44.0.1')
            ->and($this->node->roles()->pluck('role')->map(static fn (RoleName $role): string => $role->value)->sort()->values()->all())
            ->toBe(['gateway', 'vpn']);

        expect(Activity::query()->where('request_id', $requestId)->sole())
            ->command->toBe('node:update')
            ->subject_type->toBe(Node::class)
            ->subject_id->toBe($this->node->id)
            ->target_node_id->toBe($this->node->id)
            ->status->toBe('succeeded')
            ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('input'))
            ->toBe(['name' => 'vpn']);
    });

    it('treats the current name as a successful no-op', function (): void {
        Event::fake([RecordBroadcast::class]);

        $this->patchJson("/api/v1/nodes/{$this->node->id}", ['name' => 'gateway'])
            ->assertOk()
            ->assertJsonPath('data.name', 'gateway');

        Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeUpdated);
    });

    it('refuses a name another node already holds', function (): void {
        Node::query()->create(['name' => 'vpn', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.11', 'wireguard_ip' => '10.44.0.3']);

        $this->patchJson("/api/v1/nodes/{$this->node->id}", ['name' => 'vpn'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($this->node->refresh()->name)->toBe('gateway');
    });

    it('refuses malformed names without mutation', function (): void {
        $this->patchJson("/api/v1/nodes/{$this->node->id}", ['name' => 'not valid'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($this->node->refresh()->name)->toBe('gateway');
    });

    it('refuses rename while another lifecycle owner holds the current or destination name', function (): void {
        $holder = new NativeNodeProvisioningLock;

        foreach (['gateway', 'vpn'] as $held) {
            $holder->run($held, function (): void {
                $this->patchJson("/api/v1/nodes/{$this->node->id}", ['name' => 'vpn'])
                    ->assertConflict()
                    ->assertJsonPath('error.code', 'node.provisioning_busy');
            });
        }

        expect($this->node->refresh()->name)->toBe('gateway');
    });

    it('saves a name and task VM host settings together with one broadcast and no run', function (): void {
        Event::fake([RecordBroadcast::class]);

        $this->patchJson("/api/v1/nodes/{$this->node->id}", ['name' => 'host', 'task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 4]])
            ->assertOk()
            ->assertJsonPath('data.name', 'host')
            ->assertJsonPath('data.settings.task_vms.cidr', '10.251.77.0/24');

        expect($this->node->refresh()->name)->toBe('host')
            ->and(Operation::query()->count())->toBe(0);
        expect(Event::dispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeUpdated))->toHaveCount(1);
    });
});

describe('an iPadOS peer', function (): void {
    beforeEach(function (): void {
        $this->markAsGateway(Node::query()->create([
            'name' => 'gateway', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
            'ssh_host_fingerprint' => 'SHA256:'.str_repeat('G', 43),
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
        $this->peer = Node::query()->create([
            'name' => 'ipad', 'status' => LifecycleStatus::Active, 'platform' => 'ipados',
            'architecture' => 'arm64', 'public_ssh_host' => '10.44.0.12', 'user' => 'ipad',
            'wireguard_ip' => '10.44.0.12',
        ]);
    });

    it('refuses node:add before SSH and leaves the peer as it was', function (array $body): void {
        $before = $this->peer->refresh()->getAttributes();

        $this->postJson('/api/v1/nodes', ['name' => 'ipad', 'host_key_fingerprint' => 'SHA256:'.str_repeat('I', 43), ...$body])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.platform_unsupported');

        expect($this->peer->refresh()->getAttributes())->toBe($before);
    })->with([
        'linux' => [[]],
        'macos' => [['platform' => 'macos', 'user' => 'ipad', 'orbit_user' => 'ipad']],
    ]);

    it('refuses a tool scan, as on any unmanaged Node', function (): void {
        $this->withHeader('Accept', 'application/json')->get('/api/v1/tool-inventory?node_id='.$this->peer->id)
            ->assertConflict()
            ->assertJsonPath('error.code', 'tool.node_unmanaged');
    });
});
