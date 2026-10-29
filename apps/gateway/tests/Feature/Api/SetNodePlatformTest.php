<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Models\Activity;
use App\Models\Node;
use Illuminate\Support\Facades\Event;
use Orbit\Sdk\Requests\Nodes\SetNodePlatformRequest;

describe('PATCH /api/v1/nodes/{node}/platform', function (): void {
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
        $this->endpoint = '/api/v1/nodes/'.$this->peer->id.'/platform';
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
        record_fixture($response, 'nodes/node-platform/updated', SetNodePlatformRequest::class, 'PATCH /api/v1/nodes/{node}/platform');

        expect(array_diff_assoc($this->peer->refresh()->getAttributes(), $before))->toHaveKeys(['platform'])
            ->and($this->peer->platform)->toBe('ipados')
            ->and($this->peer->wireguard_public_key)->toBe(base64_encode(str_repeat('i', 32)));
        Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeUpdated
            && $event->id === $this->peer->id && $event->data['platform'] === 'ipados');
        $activity = Activity::query()->where('request_id', fixture_request_id())->sole();
        expect($activity->command)->toBe('node:platform')
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
        record_fixture($response, 'nodes/node-platform/managed-refused', SetNodePlatformRequest::class, 'PATCH /api/v1/nodes/{node}/platform');

        expect($this->peer->refresh()->platform)->toBe('linux');
    });

    it('refuses a Node with a role', function (): void {
        $this->peer->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Failed]);

        $this->patchJson($this->endpoint, ['platform' => 'ipados'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'node.platform_managed');

        expect($this->peer->refresh()->platform)->toBe('linux');
    });

    it('rejects an unknown platform, a missing platform, and extra members', function (array $body): void {
        $this->patchJson($this->endpoint, $body)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($this->peer->refresh()->platform)->toBe('linux');
    })->with([
        'unknown' => [['platform' => 'android']],
        'missing' => [[]],
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
