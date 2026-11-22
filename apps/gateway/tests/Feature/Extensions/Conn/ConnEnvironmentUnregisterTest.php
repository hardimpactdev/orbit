<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnManagedRevision;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;
use Orbit\Extensions\Conn\Models\ConnManagedToken;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnThread;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    SwampNodeFixture::ensure()->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/fixture-ca', 'swamp.run_token' => 'fixture-token']);

    $this->vault = new class implements WebSocketClient
    {
        /** @var list<array<string, mixed>> */
        public array $sent = [];

        /** @var array<string, mixed> */
        private array $last = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->sent[] = $this->last = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            return [['id' => $this->last['id'], 'type' => $this->last['type'], 'payload' => ['data' => []]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $this->vault);

    $this->host = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast',
        'status' => 'active',
        'platform' => 'linux',
        'wireguard_ip' => '10.44.1.10',
        'public_ssh_host' => '10.44.1.10',
        'user' => 'orbit',
    ]));

    $this->caller = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'macbook',
        'status' => 'active',
        'platform' => 'macos',
        'wireguard_ip' => '10.44.1.11',
        'public_ssh_host' => '10.44.1.11',
        'user' => 'orbit',
    ]));
    $this->caller->accessibleNodes()->attach($this->host->id);

    $this->stranger = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'stranger',
        'status' => 'active',
        'platform' => 'macos',
        'wireguard_ip' => '10.44.1.12',
        'public_ssh_host' => '10.44.1.12',
        'user' => 'orbit',
    ]));
});

function unregisterHostEnvironment(Node $host, string $environmentId = '2f6cdbde-0000-4000-8000-000000000001'): ConnEnvironment
{
    return ConnEnvironment::query()->create([
        'environment_id' => $environmentId,
        'label' => 'beast',
        'url' => 'http://10.44.1.10:3773',
        'node_id' => $host->id,
        'admin_session' => 'stale-admin-session',
        'admin_session_expires_at' => now()->addDays(10),
        'registered_at' => now()->subDays(5),
    ]);
}

it('unregisters a host server, removes its pairings, held sessions, managed target rows, cached threads, attached threads and vault copies, and is idempotent', function (): void {
    $envId = '2f6cdbde-0000-4000-8000-000000000001';
    $environment = unregisterHostEnvironment($this->host, $envId);
    $other = unregisterHostEnvironment($this->host, 'a412ff4d-0000-4000-8000-000000000002');

    // Device pairing and held session
    ConnPairing::query()->create([
        'node_id' => $this->caller->id,
        'conn_environment_id' => $environment->id,
        'client_label' => 'macbook via Orbit pair0001',
        'credential' => 'fixture-credential',
    ]);
    ConnPairing::query()->create([
        'node_id' => $this->caller->id,
        'conn_environment_id' => $environment->id,
        'client_label' => 'macbook via Orbit held0001',
        'held' => true,
        'session' => 'fixture-held-session',
        'session_expires_at' => now()->addDays(5),
    ]);
    // Unrelated pairing on another environment stays intact
    ConnPairing::query()->create([
        'node_id' => $this->caller->id,
        'conn_environment_id' => $other->id,
        'client_label' => 'macbook via Orbit other001',
    ]);

    // Managed target, token, and revision
    $target = ConnManagedTarget::query()->create([
        'conn_environment_id' => $environment->id,
        'environment_id' => $envId,
        'node_id' => $this->host->id,
        'generation' => 1,
        'revision' => 2,
        'credential_digest' => str_repeat('a', 64),
    ]);
    ConnManagedToken::query()->create([
        'conn_managed_target_id' => $target->id,
        'generation' => 1,
        'caller_node_id' => $this->caller->id,
        'node_access_id' => 1,
        'token_digest' => str_repeat('b', 64),
        'scopes' => ['access:read'],
        'expires_at' => now()->addHour(),
    ]);
    ConnManagedRevision::query()->create([
        'conn_managed_target_id' => $target->id,
        'generation' => 1,
        'revision' => 2,
        'edge_ids' => [1],
        'committed_at' => now()->toIso8601String(),
    ]);

    // Cached thread row (even if created on a host server)
    $project = Project::query()->create([
        'name' => 'Tool',
        'slug' => 'tool',
        'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/example/tool.git',
        'default_branch' => 'main',
        'root' => '.',
        'source_access' => ProjectSourceAccess::GitHubApp,
    ]);
    $dummyInstance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->host->id,
        'name' => 'dummy',
        'checkout_path' => '/srv/dummy',
        'status' => 'active',
    ]);
    ConnThread::query()->create([
        'conn_environment_id' => $environment->id,
        'instance_id' => $dummyInstance->id,
        'thread_id' => 'thread-stale-1',
        'title' => 'Stale thread',
        'seen_at' => now(),
    ]);

    // Attached thread in profile
    $profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson('/api/v1/conn/profiles/'.$profile->id.'/settings', [
            'sections' => [
                'attachedThreads' => [
                    'version' => 0,
                    'value' => [
                        'schema' => 1,
                        'threads' => [
                            ['environmentId' => $envId, 'threadId' => 't-1'],
                            ['environmentId' => $other->environment_id, 'threadId' => 't-2'],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

    // Terminal conn.* Operation holding an execution secret vault copy
    Operation::query()->create([
        'kind' => 'conn.gateway_sessions_revoke',
        'target_type' => 'conn_environment',
        'target_id' => $environment->id,
        'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Succeeded,
        'input' => [
            'conn_environment_id' => $environment->id,
            'execution_secrets' => [
                'admin_session' => ['vault' => 'orbit-control-inputs', 'key' => 'conn-unregister-secret-key'],
            ],
        ],
        'state' => [],
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->deleteJson('/api/v1/conn/environments/'.$envId)
        ->assertOk()
        ->assertJsonPath('data.environment_id', $envId)
        ->assertJsonPath('data.removed', true);

    expect($response->json('meta.request_id'))->toBeString()
        ->and(ConnEnvironment::query()->where('environment_id', $envId)->exists())->toBeFalse()
        ->and(ConnEnvironment::query()->where('environment_id', $other->environment_id)->exists())->toBeTrue()
        ->and(ConnPairing::query()->where('conn_environment_id', $environment->id)->exists())->toBeFalse()
        ->and(ConnPairing::query()->where('conn_environment_id', $other->id)->count())->toBe(1)
        ->and(ConnManagedTarget::query()->where('conn_environment_id', $environment->id)->exists())->toBeFalse()
        ->and(ConnManagedToken::query()->where('conn_managed_target_id', $target->id)->exists())->toBeFalse()
        ->and(ConnManagedRevision::query()->where('conn_managed_target_id', $target->id)->exists())->toBeFalse()
        ->and(ConnThread::query()->where('conn_environment_id', $environment->id)->exists())->toBeFalse()
        ->and($profile->fresh()?->settings['attachedThreads']['threads'])->toBe([
            ['environmentId' => $other->environment_id, 'threadId' => 't-2'],
        ])
        ->and(collect($this->vault->sent)->where('type', 'vault.delete')->pluck('payload.key')->all())->toBe(['conn-unregister-secret-key']);

    // No longer shows in conn:environment:list
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/conn/environments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.environment_id', $other->environment_id);

    // Second call is idempotent and returns removed: false with 200
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->deleteJson('/api/v1/conn/environments/'.$envId)
        ->assertOk()
        ->assertJsonPath('data.environment_id', $envId)
        ->assertJsonPath('data.removed', false);
});

it('returns removed false with 200 for an unknown environment id', function (): void {
    $unknown = '946ca845-0000-4000-8000-000000000099';

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->deleteJson('/api/v1/conn/environments/'.$unknown)
        ->assertOk()
        ->assertJsonPath('data.environment_id', $unknown)
        ->assertJsonPath('data.removed', false);
});

it('forbids callers without node access to the server node', function (): void {
    $environment = unregisterHostEnvironment($this->host);

    // Caller with no node access at all
    $this->withServerVariables(['REMOTE_ADDR' => $this->stranger->wireguard_ip])
        ->deleteJson('/api/v1/conn/environments/'.$environment->environment_id)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    // Caller with node access to a different Node, not the server's Node
    $otherHost = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'other-host',
        'status' => 'active',
        'platform' => 'linux',
        'wireguard_ip' => '10.44.1.13',
        'public_ssh_host' => '10.44.1.13',
        'user' => 'orbit',
    ]));
    $this->stranger->accessibleNodes()->attach($otherHost->id);

    $this->withServerVariables(['REMOTE_ADDR' => $this->stranger->wireguard_ip])
        ->deleteJson('/api/v1/conn/environments/'.$environment->environment_id)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    expect($environment->fresh())->not->toBeNull();
});

it('refuses an Instance-bound environment with conn.environment_is_instance', function (): void {
    $project = Project::query()->create([
        'name' => 'App',
        'slug' => 'app',
        'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/example/app.git',
        'default_branch' => 'main',
        'root' => '.',
        'source_access' => ProjectSourceAccess::GitHubApp,
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->host->id,
        'name' => 'clone-1',
        'checkout_path' => '/srv/clone-1',
        'status' => 'active',
    ]);
    $environment = unregisterHostEnvironment($this->host, 'inst-env-1');
    $environment->forceFill(['instance_id' => $instance->id])->save();

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->deleteJson('/api/v1/conn/environments/'.$environment->environment_id)
        ->assertConflict()
        ->assertJsonPath('error.code', 'conn.environment_is_instance');

    expect($environment->fresh())->not->toBeNull();
});

it('refuses while a conn operation is running on the environment with operation.target_busy', function (string $kind, string $targetType, bool $locked): void {
    $environment = unregisterHostEnvironment($this->host);

    Operation::query()->create([
        'kind' => $kind,
        'target_type' => $targetType,
        'target_id' => $environment->id,
        'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Running,
        'input' => ['conn_environment_id' => $environment->id],
        'state' => [],
        'lock_key' => $locked ? OperationStore::lockKey('conn_environment', $environment->id) : null,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->deleteJson('/api/v1/conn/environments/'.$environment->environment_id)
        ->assertConflict()
        ->assertJsonPath('error.code', 'operation.target_busy');

    expect($environment->fresh())->not->toBeNull();
})->with([
    'cleanup' => ['conn.gateway_sessions_revoke', 'conn_environment', true],
    'pairing create' => ['conn.pairing_create', 'conn_pairing', false],
]);

it('requires the conn extension to be enabled', function (): void {
    $environment = unregisterHostEnvironment($this->host);
    app(ExtensionStore::class)->set('conn', false);

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->deleteJson('/api/v1/conn/environments/'.$environment->environment_id)
        ->assertConflict()
        ->assertJsonPath('error.code', 'extension.disabled');

    expect($environment->fresh())->not->toBeNull();
});

it('publishes conn-environment-unregister as a destructive conn MCP tool', function (): void {
    $manifest = json_decode((string) file_get_contents(resource_path('mcp/tools.json')), true, flags: JSON_THROW_ON_ERROR);
    $tool = collect($manifest['tools'])->firstWhere('name', 'conn-environment-unregister');

    expect($tool)->toBeArray()
        ->and($tool['extension'])->toBe('conn')
        ->and($tool['method'])->toBe('DELETE')
        ->and($tool['path'])->toBe('/api/v1/conn/environments/{environment}')
        ->and($tool['destructive'])->toBeTrue()
        ->and($tool['starts_operation'])->toBeFalse()
        ->and($tool['path_inputs'])->toBe(['environment']);
});
