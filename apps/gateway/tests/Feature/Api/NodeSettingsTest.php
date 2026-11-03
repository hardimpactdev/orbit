<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\NativeNodeEnrollmentFixtures;

function fake_storage_preparer(): NodeStorageRootPreparer
{
    return new class implements NodeStorageRootPreparer
    {
        public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void {}

        public function prepare(Node $node, ManagedUserAccount $account, StoragePath $root): void {}
    };
}

describe('node storage settings', function (): void {
    beforeEach(function (): void {
        app()->instance(NodeStorageRootPreparer::class, fake_storage_preparer());
        app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
            }
        });
        $this->operator = Node::query()->create([
            'name' => 'operator',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.2',
        ]);
        $this->markAsGateway($this->operator);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    });

    it('admits typed raw settings and returns them without substituting defaults', function (): void {
        $this->beforeApplicationDestroyed(NativeNodeEnrollmentFixtures::bind());
        $requestId = (string) Str::uuid();

        $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->postJson('/api/v1/nodes', [
                'name' => 'app-dev',
                'public_ssh_host' => '192.0.2.10',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'dev.test',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
                'settings' => [
                    'apps' => ['path' => '/srv/orbit/apps'],
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'node.add');

        $node = Node::query()->where('name', 'app-dev')->sole();

        expect(Operation::query()->where('kind', 'node.add')->sole()->input['_node_enrollment']['settings'])
            ->toBe([
                'apps' => ['path' => '/srv/orbit/apps'],
            ]);
    });

    it('admits unset settings as SQL null without materializing defaults', function (): void {
        $this->beforeApplicationDestroyed(NativeNodeEnrollmentFixtures::bind());
        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'app-dev',
                'public_ssh_host' => '192.0.2.10',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'dev.test',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
            ])
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'node.add');

        expect(Node::query()->where('name', 'app-dev')->sole()->settings)->toBeNull();
    });

    it('rejects unknown members and unknown settings keys without persisting', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}", [
                'packages' => ['path' => '/srv/orbit/packages'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
        $this
            ->patchJson("/api/v1/nodes/{$node->id}", ['apps' => ['path' => '/srv/apps', 'mode' => '0700']])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_invalid');

        expect($node->refresh()->settings)->toBeNull();
    });

    it('rejects obviously wrong apps paths before persisting', function (string $path, string $code): void {
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}", ['apps' => ['path' => $path]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', $code);

        expect($node->refresh()->settings)->toBeNull();
    })->with([['/', 'node.settings_path_invalid'], ...array_map(static fn (string $path): array => [$path, 'node.settings_path_protected'],
        ['/home', '/home/orbit', '/home/alice', '/root', '/etc', '/usr'])]);

    it('rejects protected roots on a node without app-dev before persisting', function (): void {
        $inspected = [];
        $prepared = [];
        app()->instance(NodeStorageRootPreparer::class, recording_storage_preparer($inspected, $prepared));
        $node = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}", [
                'apps' => ['path' => '/etc/orbit'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_protected');

        expect($node->refresh()->settings)
            ->toBeNull()
            ->and($prepared)
            ->toBe([]);
    });

    it('rejects an empty nested path instead of treating it as an unset', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => [
                'apps' => ['path' => '/srv/orbit/apps'],
            ],
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}", [
                'apps' => ['path' => ''],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_invalid');

        expect($node->refresh()->settings)
            ->toBe([
                'apps' => ['path' => '/srv/orbit/apps'],
            ]);
    });

    it('rejects roots that overlap the configured Gateway checkout', function (string $path): void {
        config()->set('orbit.gateway_checkout', '/srv/orbit-gateway');
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}", [
                'apps' => ['path' => $path],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_protected');

        expect($node->refresh()->settings)->toBeNull();
    })->with([
        'equal' => '/srv/orbit-gateway',
        'inside' => '/srv/orbit-gateway/app',
        'contains' => '/srv',
    ]);

    it('rejects roots that overlap the Gateway release layout', function (string $path): void {
        config()->set('orbit.gateway_checkout', '/srv/orbit/apps/gateway');
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        $this
            ->patchJson("/api/v1/nodes/{$node->id}", [
                'apps' => ['path' => $path],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.settings_path_protected');
    })->with([
        'current link' => '/srv/orbit',
        'releases' => '/srv/releases',
        'one release' => '/srv/releases/0123456789ab',
        'shared state' => '/srv/shared/gateway-storage',
    ]);

    it('refuses a hidden folder in the managed home at admission without starting a run', function (string $path): void {
        $node = Node::query()->create([
            'name' => 'app-dev', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10', 'user' => 'orbit',
            'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.3', 'ssh_host_fingerprint' => 'SHA256:test',
            'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND',
        ]);
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
        $socket = new FakeRoutePublicationSocket;
        $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
            'kind' => 'started', 'workflowName' => 'operation-node-update', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        ]]];
        app()->instance(WebSocketClient::class, $socket);
        config(['swamp.run_token' => 'fake.token', 'swamp_workers.nodes' => []]);
        $agents = Mockery::mock(AgentCommandRunner::class);
        $agents->shouldNotReceive('run', 'install');
        app()->instance(AgentCommandRunner::class, $agents);
        $this->patchJson("/api/v1/nodes/{$node->id}", ['apps' => ['path' => $path]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'node.settings_path_protected');
        expect($node->refresh()->settings)->toBeNull()
            ->and($socket->sent)->toBe([]);
    })->with(['/home/orbit/.orbit/worktrees/instances', '/home/orbit/.orbit/worktrees/extra']);

    it('saves a new name at once and starts one node.update run for the apps folder in the same request', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10', 'user' => 'orbit',
            'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.3', 'ssh_host_fingerprint' => 'SHA256:test',
            'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND',
        ]);
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
        $socket = new FakeRoutePublicationSocket;
        $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
            'kind' => 'started', 'workflowName' => $message['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        ]]];
        app()->instance(WebSocketClient::class, $socket);
        config(['swamp.run_token' => 'fake.token', 'swamp_workers.nodes' => []]);

        $this->patchJson("/api/v1/nodes/{$node->id}", ['name' => 'renamed', 'apps' => ['path' => '/srv/apps']])
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'node.update');

        expect($node->refresh())->name->toBe('renamed')->settings->toBeNull()
            ->and($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-node-update')
            ->and($socket->sent[0]['payload']['inputs']['intent']['storage'])->toBe(['root' => '/srv/apps', 'user' => 'orbit', 'group' => 'orbit']);
    });

    it('rejects list settings at every HTTP boundary without changing stored intent', function (
        string $method,
        string $path,
        array $payload,
        ?array $stored,
        string $code = 'node.settings_invalid',
    ): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'settings' => $stored,
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
        $url = $path === 'settings' ? "/api/v1/nodes/{$node->id}" : '/api/v1/nodes';

        $this
            ->json($method, $url, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', $code);

        expect($node->refresh()->settings)->toBe($stored);
        if ($path === 'provision') {
            expect(Node::query()->where('name', 'listed')->exists())->toBeFalse();
        }
    })->with([
        'patch list body' => [
            'PATCH',
            'settings',
            [],
            ['apps' => ['path' => '/srv/orbit/apps']],
            'validation.failed',
        ],
        'patch nested list' => [
            'PATCH',
            'settings',
            ['apps' => []],
            ['apps' => ['path' => '/srv/orbit/apps']],
        ],
        'provision list settings' => [
            'POST',
            'provision',
            [
                'name' => 'listed',
                'public_ssh_host' => '192.0.2.11',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'dev.test',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
                'settings' => [],
            ],
            null,
        ],
        'provision nested list' => [
            'POST',
            'provision',
            [
                'name' => 'listed',
                'public_ssh_host' => '192.0.2.11',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'dev.test',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
                'settings' => ['apps' => []],
            ],
            null,
        ],
    ]);
});

function recording_storage_preparer(array &$inspected, array &$prepared): NodeStorageRootPreparer
{
    return new class($inspected, $prepared) implements NodeStorageRootPreparer
    {
        /**
         * @param  list<string>  $inspected
         * @param  list<string>  $prepared
         */
        public function __construct(
            private array &$inspected,
            private array &$prepared,
        ) {}

        public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void
        {
            $this->inspected[] = $path->value;
        }

        public function prepare(Node $node, ManagedUserAccount $account, StoragePath $root): void
        {
            $this->prepared[] = $root->value;
        }
    };
}
