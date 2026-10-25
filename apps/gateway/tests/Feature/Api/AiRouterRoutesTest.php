<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmState;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route as OrbitRoute;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\AiRouter\Domain\AiRouterAccount;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterWindow;
use Orbit\Extensions\AiRouter\Http\Controllers\AiRouterController;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Orbit\Extensions\AiRouter\Infrastructure\RecordingAiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Infrastructure\RecordingAiRouterRuntimeLifecycle;

function ai_router_gateway(): Node
{
    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    test()->markAsGateway($node);
    app(ExtensionStore::class)->set('ai-router', true);

    return $node;
}

function ai_router_node(string $name = 'beast', string $ip = '10.44.0.8'): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'wireguard_ip' => $ip,
    ]);
}

function ai_router_hostname_route(Node $node, string $upstream, ?int $processId = null): OrbitRoute
{
    $route = OrbitRoute::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $node->id,
        'domain' => 'collector.ai-router.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->customProxy()->create(['node_id' => $node->id, 'process_id' => $processId, 'upstream' => $upstream]);
    $route->update(['status' => RouteStatus::Active]);

    return $route;
}

function ai_router_valkey(Node $node, string $slug = 'valkey'): DatabaseConnection
{
    $node->roles()->create(['role' => RoleName::Database, 'status' => LifecycleStatus::Active]);

    return DatabaseConnection::query()->create([
        'slug' => $slug,
        'driver' => DatabaseDriver::Redis,
        'node_id' => $node->id,
        'host' => $node->wireguard_ip,
        'port' => 6379,
        'database' => '0',
    ]);
}

it('exposes the ai-router routes with stable methods', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'ai-router:'))
        ->mapWithKeys(static fn (Route $route): array => [
            $route->getName() => [$route->uri(), $route->methods()],
        ])
        ->all();

    expect($routes)->toBe([
        'ai-router:setup' => ['api/v1/ai-router', ['POST']],
        'ai-router:teardown' => ['api/v1/ai-router', ['DELETE']],
        'ai-router:status' => ['api/v1/ai-router', ['GET', 'HEAD']],
        'ai-router:provider:list' => ['api/v1/ai-router/providers', ['GET', 'HEAD']],
        'ai-router:provider:show' => ['api/v1/ai-router/providers/{provider}', ['GET', 'HEAD']],
        'ai-router:model:list' => ['api/v1/ai-router/models', ['GET', 'HEAD']],
        'ai-router:account:enable' => ['api/v1/ai-router/accounts/{account}/enable', ['POST']],
        'ai-router:account:disable' => ['api/v1/ai-router/accounts/{account}/disable', ['POST']],
    ]);
});

it('requires Gateway authorization for every ai-router action', function (): void {
    $attribute = new ReflectionClass(AiRouterController::class)
        ->getAttributes(RequiresNodeAccess::class)[0]
        ->newInstance();

    expect($attribute->servingNode)->toBe(ServingNode::Gateway);
});

it('enables ai-router when shared Valkey sits on a database Node', function (): void {
    config(['task_vms.enabled' => false, 'task_vms.model_proxy_origin' => 'http://10.44.0.3:8317']);
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.hostname', 'collector.ai-router.orbit')
        ->assertJsonPath('data.node_id', $node->id)
        ->assertJsonPath('data.cache_connection', 'valkey')
        ->assertJsonMissingPath('data.cliproxy_management_key')
        ->assertJsonMissingPath('data.read_token');

    $environment = app(RecordingAiRouterRuntimeLifecycle::class)->environment;

    $process = Process::query()->where('name', AiRouterProcess::NAME)->first();

    expect($process)->not->toBeNull()
        ->and($process->runtime_config['command'])->toBe([
            AiRouterProcess::EXECUTABLE,
            'service',
            'ai-router-collector',
        ])
        ->and($process->runtime_config['environment']['AI_ROUTER_CACHE_HOST'])->toBe('10.44.0.8')
        ->and($process->runtime_config['environment']['AI_ROUTER_CACHE_PORT'])->toBe('6379')
        ->and($process->runtime_config['environment']['AI_ROUTER_CLIPROXY_URL'])->toBe('http://127.0.0.1:8317')
        ->and(app(RecordingAiRouterRuntimeLifecycle::class)->converged)->toBeTrue()
        ->and(app(RecordingAiRouterPublicationManager::class)->converged)->toBeTrue()
        ->and($environment['AI_ROUTER_CACHE_HOST'])->toBe('10.44.0.8')
        ->and($environment['AI_ROUTER_CACHE_PORT'])->toBe('6379');
});

it('refuses a mismatching task VM origin with 422 before changing settings, collector or publication', function (bool $alreadyEnabled): void {
    config(['task_vms.enabled' => false]);
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);
    $payload = [
        'node_id' => $node->id,
        'cache_connection' => 'valkey',
        'cliproxy_url' => 'http://127.0.0.1:8317',
        'cliproxy_management_key' => 'replacement-management-key',
    ];
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    if ($alreadyEnabled) {
        $this->postJson('/api/v1/ai-router', [
            ...$payload,
            'cliproxy_url' => 'http://10.44.0.3:8317',
            'cliproxy_management_key' => 'original-management-key',
        ])->assertCreated();
    }
    config(['task_vms.enabled' => true, 'task_vms.model_proxy_origin' => 'http://10.44.0.3:8317']);
    $settings = DB::table('settings')->orderBy('id')->get()->toArray();
    $processes = Process::query()->get()->toArray();
    $routes = OrbitRoute::query()->get()->toArray();
    $runtime = app(RecordingAiRouterRuntimeLifecycle::class);
    $publication = app(RecordingAiRouterPublicationManager::class);
    $runtime->converged = false;
    $publication->converged = false;

    $this->postJson('/api/v1/ai-router', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.cliproxy_url.0', 'The cliproxy_url must equal task_vms.model_proxy_origin while task VMs are enabled.');

    expect(DB::table('settings')->orderBy('id')->get()->toArray())->toEqual($settings);
    expect(Process::query()->get()->toArray())->toBe($processes);
    expect(OrbitRoute::query()->get()->toArray())->toBe($routes);
    expect($runtime->converged)->toBeFalse();
    expect($publication->converged)->toBeFalse();
})->with(['first setup' => false, 'existing setup' => true]);

it('enables ai-router with the matching task VM origin and normalizes trailing slashes', function (string $url, string $origin): void {
    config(['task_vms.enabled' => true, 'task_vms.model_proxy_origin' => $origin]);
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => $url,
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated()
        ->assertJsonPath('data.enabled', true);

    expect(app(AiRouterState::class)->routerUrl())->toBe('http://10.44.0.3:8317');
    expect(app(RecordingAiRouterRuntimeLifecycle::class)->environment['AI_ROUTER_CLIPROXY_URL'])->toBe('http://10.44.0.3:8317');
    expect(Process::query()->where('name', AiRouterProcess::NAME)->firstOrFail()->runtime_config['environment']['AI_ROUTER_CLIPROXY_URL'])
        ->toBe('http://10.44.0.3:8317');
    expect(app(RecordingAiRouterPublicationManager::class)->converged)->toBeTrue();
})->with([
    'exact origin' => ['http://10.44.0.3:8317', 'http://10.44.0.3:8317'],
    'setup trailing slash' => ['http://10.44.0.3:8317/', 'http://10.44.0.3:8317'],
    'configured trailing slash' => ['http://10.44.0.3:8317', 'http://10.44.0.3:8317/'],
]);

it('reconverges the selected collector after a Database connection update', function (bool $switch): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    $connection = ai_router_valkey($node);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id, 'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317', 'cliproxy_management_key' => 'management-key',
        ])->assertCreated();
    $process = Process::query()->where('name', AiRouterProcess::NAME)->sole();
    $tokens = [app(AiRouterState::class)->readToken(), app(AiRouterState::class)->controlToken()];
    app(ExtensionStore::class)->set('ai-router', $switch);
    $this->patchJson('/api/v1/database-connections/'.$connection->slug, [
        'host' => '10.44.0.9', 'port' => 6380, 'username' => 'collector', 'password' => 'new-cache-secret',
    ])->assertOk()->assertDontSee('new-cache-secret');
    expect($process->refresh()->runtime_config['environment'])->toMatchArray([
        'AI_ROUTER_CACHE_HOST' => '10.44.0.9', 'AI_ROUTER_CACHE_PORT' => '6380',
        'AI_ROUTER_CACHE_USERNAME' => 'collector', 'AI_ROUTER_CACHE_PASSWORD' => 'new-cache-secret',
        'AI_ROUTER_READ_TOKEN' => $tokens[0], 'AI_ROUTER_CONTROL_TOKEN' => $tokens[1],
    ])->and(Process::query()->where('name', AiRouterProcess::NAME)->sole()->id)->toBe($process->id);
})->with([true, false]);

it('refuses incompatible proposed selected connections without changing connection or collector', function (string $change, string $error): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    $connection = ai_router_valkey($node);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id, 'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317', 'cliproxy_management_key' => 'management-key',
        ])->assertCreated();
    $process = Process::query()->where('name', AiRouterProcess::NAME)->sole();
    Event::fake([RecordBroadcast::class]);
    $beforeConnection = $connection->refresh()->getRawOriginal();
    $beforeProcess = $process->getRawOriginal();
    $beforeEnvironment = app(RecordingAiRouterRuntimeLifecycle::class)->environment;
    $tokens = [app(AiRouterState::class)->readToken(), app(AiRouterState::class)->controlToken()];
    $body = $change === 'sqlite'
        ? ['driver' => 'sqlite', 'path' => '/tmp/cache.sqlite', 'database' => null, 'host' => null, 'port' => null]
        : ['node_id' => ai_router_node('without-database', '10.44.0.9')->id, 'host' => '10.44.0.9', 'password' => 'refused-secret'];
    $this->patchJson('/api/v1/database-connections/'.$connection->slug, $body)
        ->assertUnprocessable()->assertJsonPath('error.code', $error);
    Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DatabaseUpdated);
    expect($connection->refresh()->getRawOriginal())->toBe($beforeConnection)
        ->and($process->refresh()->getRawOriginal())->toBe($beforeProcess)
        ->and(app(RecordingAiRouterRuntimeLifecycle::class)->environment)->toBe($beforeEnvironment)
        ->and([app(AiRouterState::class)->readToken(), app(AiRouterState::class)->controlToken()])->toBe($tokens);
})->with(['driver' => ['sqlite', 'ai_router.cache_invalid'], 'placement' => ['node', 'ai_router.cache_unplaced']]);

it('keeps the management key out of activity for every enable outcome', function (bool $placed, int $status): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    $key = 'management-'.Str::random(32);

    if ($placed) {
        ai_router_valkey($node);
    }

    $requestId = (string) Str::uuid();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => $key,
        ])
        ->assertStatus($status);

    $activity = Activity::query()->where('request_id', $requestId)->sole();
    $stored = (string) DB::table('activity_log')->where('id', $activity->id)->value('properties');

    expect($activity->command)->toBe('ai-router:setup')
        ->and($activity->properties?->get('input'))->toBe([
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => '[REDACTED]',
        ])
        ->and($stored)->not->toContain($key);
})->with([
    'succeeded' => [true, 201],
    'failed' => [false, 422],
]);

it('keeps the collector hostname on a second enable', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);
    $payload = [
        'node_id' => $node->id,
        'cache_connection' => 'valkey',
        'cliproxy_url' => 'http://127.0.0.1:8317',
        'cliproxy_management_key' => 'management-key',
    ];

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', $payload)
        ->assertCreated()
        ->assertJsonPath('data.hostname', 'collector.ai-router.orbit');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', $payload)
        ->assertCreated()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.hostname', 'collector.ai-router.orbit');
});

it('hands the collector custom proxy Route to the publication takeover', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);
    $route = ai_router_hostname_route($node, 'http://127.0.0.1:8787');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated()
        ->assertJsonPath('data.hostname', 'collector.ai-router.orbit');

    expect(app(RecordingAiRouterPublicationManager::class)->takeoverRouteId)->toBe($route->id);
});

it('takes over the Route before it restarts the collector', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);
    $route = ai_router_hostname_route($node, 'http://127.0.0.1:8787');
    $events = new ArrayObject;
    app()->instance(AiRouterPublicationManager::class, new class($events) implements AiRouterPublicationManager
    {
        public function __construct(private ArrayObject $events) {}

        public function converge(Node $node, ?OrbitRoute $takeover = null): void
        {
            $this->events->append("publication:route-{$takeover?->id}");
        }

        public function remove(Node $node): void {}
    });
    app()->instance(AiRouterRuntimeLifecycle::class, new class($events) implements AiRouterRuntimeLifecycle
    {
        public function __construct(private ArrayObject $events) {}

        public function converge(Node $node, array $environment): void
        {
            $this->events->append('runtime');
        }

        public function remove(Node $node): void {}
    });

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    expect($events->getArrayCopy())->toBe(["publication:route-{$route->id}", 'runtime']);
});

it('refuses to enable while another Route holds the collector hostname', function (Closure $route): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);
    $holder = $route($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'ai_router.hostname_taken');

    expect(app(AiRouterState::class)->enabled())->toBeFalse()
        ->and(app(RecordingAiRouterRuntimeLifecycle::class)->converged)->toBeFalse()
        ->and(app(RecordingAiRouterPublicationManager::class)->converged)->toBeFalse()
        ->and(OrbitRoute::query()->whereKey($holder->id)->exists())->toBeTrue();
})->with([
    'another Node' => [fn (Node $node): OrbitRoute => ai_router_hostname_route(ai_router_node('services', '10.44.0.17'), 'http://127.0.0.1:8787')],
    'another port' => [fn (Node $node): OrbitRoute => ai_router_hostname_route($node, 'http://127.0.0.1:8317')],
    'a Process target' => [fn (Node $node): OrbitRoute => ai_router_hostname_route($node, 'http://127.0.0.1:8787', Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'collector-copy',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'collector:latest',
            'command' => ['collector'],
            'environment' => [],
            'ports' => ['127.0.0.1:8787:8787/tcp'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ])->id)],
]);

it('fails closed when the cache connection is missing, not redis, or unplaced', function (string $setup, string $code): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();

    if ($setup === 'mysql') {
        DatabaseConnection::query()->create([
            'slug' => 'valkey',
            'driver' => DatabaseDriver::Mysql,
            'node_id' => $node->id,
            'host' => '10.44.0.8',
            'port' => 3306,
            'database' => 'app',
        ]);
    }

    if ($setup === 'unplaced') {
        DatabaseConnection::query()->create([
            'slug' => 'valkey',
            'driver' => DatabaseDriver::Redis,
            'node_id' => $node->id,
            'host' => '10.44.0.8',
            'port' => 6379,
            'database' => '0',
        ]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', $code);
})->with([
    'missing' => ['missing', 'ai_router.cache_missing'],
    'mysql' => ['mysql', 'ai_router.cache_invalid'],
    'unplaced' => ['unplaced', 'ai_router.cache_unplaced'],
]);

it('hides provider reads until the fleet feature is enabled', function (): void {
    $gateway = ai_router_gateway();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/ai-router/providers')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ai_router.disabled');
});

it('reads providers from the snapshot and never calls CLIProxyAPI', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);
    Http::fake();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    app(AiRouterSnapshotStore::class)->write([
        new AiRouterAccount('plus.json', 'codex', 'plus', false, 'enabled', [
            new AiRouterWindow('7d', 30),
            new AiRouterWindow('5h', 12),
        ]),
    ], '2026-09-20T12:00:00+00:00');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/ai-router/providers')
        ->assertOk()
        ->assertJsonPath('data.0.provider', 'codex')
        ->assertJsonPath('data.0.windows.0.label', '7d')
        ->assertJsonPath('data.0.windows.1.label', '5h');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/ai-router/providers')
        ->assertOk();

    Http::assertNothingSent();
});

it('toggles an account through the collector using its auth index', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    app(AiRouterSnapshotStore::class)->write([
        new AiRouterAccount('auth-index-42', 'codex', 'plus', false, 'enabled', [
            new AiRouterWindow('7d', 30),
        ]),
    ], '2026-09-20T12:00:00+00:00');

    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->once()->andReturn('test Orbit root certificate');
    app()->instance(LeafCertificateSigner::class, $signer);
    Http::fake();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router/accounts/auth-index-42/disable')
        ->assertOk()
        ->assertJsonPath('data.id', 'auth-index-42')
        ->assertJsonPath('data.disabled', true);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://10.44.0.8/v1/accounts/auth-index-42'
        && $request->method() === 'PATCH'
        && $request->hasHeader('Host', 'collector.ai-router.orbit')
        && $request->hasHeader('Authorization', 'Bearer '.app(AiRouterState::class)->controlToken())
        && $request['disabled'] === true);

    expect(app(AiRouterSnapshotStore::class)->accounts()[0]->disabled)->toBeTrue();
});

it('disables the extension, stops the process, and hides provider reads', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/ai-router')
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    expect(Process::query()->where('name', AiRouterProcess::NAME)->exists())->toBeFalse()
        ->and(app(RecordingAiRouterRuntimeLifecycle::class)->removed)->toBeTrue()
        ->and(app(RecordingAiRouterPublicationManager::class)->removed)->toBeTrue();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/ai-router/providers')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ai_router.disabled');
});

it('refuses the teardown with 409 and changes nothing while Tasks needs it or a task VM holds a model key', function (Closure $hold, string $code, array $details, string $message): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();
    $hold($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/ai-router')
        ->assertStatus(409)
        ->assertJsonPath('error.code', $code)
        ->assertJsonPath('error.details', $details)
        ->assertJsonPath('error.message', fn (string $text): bool => str_starts_with($text, $message));

    expect(app(AiRouterState::class)->managementKey())->toBe('management-key')
        ->and(app(AiRouterState::class)->enabled())->toBeTrue()
        ->and(Process::query()->where('name', AiRouterProcess::NAME)->exists())->toBeTrue()
        ->and(app(RecordingAiRouterRuntimeLifecycle::class)->removed)->toBeFalse()
        ->and(app(RecordingAiRouterPublicationManager::class)->removed)->toBeFalse();
})->with([
    'tasks is enabled' => [
        static fn (): mixed => app(ExtensionStore::class)->set('tasks', true),
        'extension.required',
        ['extension' => 'ai-router', 'required_by' => 'tasks'],
        'The tasks extension needs the ai-router extension.',
    ],
    'a task VM holds a model key' => [
        static function (Node $node): void {
            $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
            $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Export', 'brief' => 'Work', 'status' => 'running']);
            TaskVm::query()->create([
                'group_id' => $group->id, 'host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-1',
                'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.130', 'model_key' => str_repeat('a', 64),
            ]);
        },
        'ai_router.keys_outstanding',
        ['task_vms' => 1],
        '1 task VM holds a model key that',
    ],
]);

it('holds the model key lock from the key check through the teardown', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);
    $publication = new class implements AiRouterPublicationManager
    {
        public ?bool $lockFree = null;

        public function converge(Node $node, ?OrbitRoute $takeover = null): void {}

        public function remove(Node $node): void
        {
            // The management key is already gone here, so a key issued now could never be revoked.
            expect(app(AiRouterState::class)->managementKey())->toBeNull();
            $lock = Cache::lock(ModelKeyIssuer::Lock, 10);
            $this->lockFree = $lock->get();
            if ($this->lockFree) {
                $lock->release();
            }
        }
    };
    app()->instance(AiRouterPublicationManager::class, $publication);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/ai-router')
        ->assertOk();

    expect($publication->lockFree)->toBeFalse()
        ->and(Cache::lock(ModelKeyIssuer::Lock, 10)->get())->toBeTrue();
});

it('refuses the teardown with 409 and changes nothing while a model key operation holds the lock', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();
    $prepare = Cache::lock(ModelKeyIssuer::Lock, 600);
    expect($prepare->get())->toBeTrue();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/ai-router')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ai_router.keys_busy');

    expect(app(AiRouterState::class)->managementKey())->toBe('management-key')
        ->and(app(RecordingAiRouterRuntimeLifecycle::class)->removed)->toBeFalse()
        ->and(app(RecordingAiRouterPublicationManager::class)->removed)->toBeFalse();
    $prepare->release();
});

it('republishes private DNS without the collector name when the extension is disabled', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    ai_router_valkey($node);
    $publication = new class implements AiRouterPublicationManager
    {
        public ?string $dnsAtRemoval = null;

        public function converge(Node $node, ?OrbitRoute $takeover = null): void {}

        public function remove(Node $node): void
        {
            $this->dnsAtRemoval = app(DevelopmentDnsConfigRenderer::class)->render();
        }
    };
    app()->instance(AiRouterPublicationManager::class, $publication);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    expect(app(DevelopmentDnsConfigRenderer::class)->render())->toContain('host-record=collector.ai-router.orbit,10.44.0.8');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/ai-router')
        ->assertOk();

    expect($publication->dnsAtRemoval)->toBeString()
        ->not->toContain('collector.ai-router.orbit');
});

it('refuses removing the selected cache until teardown even with the extension switch off', function (bool $switch): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    $connection = ai_router_valkey($node);
    app(AiRouterState::class)->enable($node->id, $connection->slug, 'http://127.0.0.1:8317', 'management', 'read', 'control');
    app(ExtensionStore::class)->set('ai-router', $switch);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    Event::fake([RecordBroadcast::class]);

    $this->deleteJson('/api/v1/database-connections/'.$connection->slug)
        ->assertConflict()->assertJsonPath('error.code', 'database_connection.required_by_ai_router');

    $this->assertModelExists($connection);
    Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DatabaseDeleted);
    expect(app(RecordingAiRouterRuntimeLifecycle::class)->removed)->toBeFalse();

    $other = DatabaseConnection::query()->create(['slug' => 'unrelated', 'driver' => 'redis', 'host' => 'other.example.test', 'port' => 6379]);
    $this->deleteJson('/api/v1/database-connections/'.$other->slug)->assertOk();
    $this->assertModelMissing($other);

    app(AiRouterState::class)->disable();
    $this->deleteJson('/api/v1/database-connections/'.$connection->slug)->assertOk();
    $this->assertModelMissing($connection);
})->with([true, false]);

it('uses one fixed port for setup, takeover, publication, and connection reconvergence', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    $connection = ai_router_valkey($node);
    $route = ai_router_hostname_route($node, 'http://127.0.0.1:8787');
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id, 'cache_connection' => $connection->slug,
            'cliproxy_url' => 'http://127.0.0.1:8317', 'cliproxy_management_key' => 'management',
        ])->assertCreated();
    $process = Process::query()->where('name', AiRouterProcess::NAME)->sole();
    $environment = $process->runtime_config['environment'];
    expect($environment['AI_ROUTER_PORT'])->toBe('8787')
        ->and($process->runtime_config['ports'])->toBe(['127.0.0.1:8787:8787/tcp'])
        ->and(app(RecordingAiRouterPublicationManager::class)->takeoverRouteId)->toBe($route->id);
    $this->assertDatabaseMissing('settings', ['scope_type' => 'gateway', 'scope_id' => 0, 'key' => 'ai_router.process_port']);
    new CaddySiteCertificates()->record($node->id, AiRouterCaddySiteSource::CertificateSlug);
    $sites = app(AiRouterCaddySiteSource::class)->sites($node);
    expect($sites)->toHaveCount(1)
        ->and($sites[0]->body)->toContain('reverse_proxy 127.0.0.1:8787');

    $this->patchJson('/api/v1/database-connections/'.$connection->slug, ['password' => 'rotated'])->assertOk();

    expect($process->refresh()->runtime_config['environment'])->toBe([
        ...$environment, 'AI_ROUTER_CACHE_PASSWORD' => 'rotated',
    ])->and($process->runtime_config['ports'])->toBe(['127.0.0.1:8787:8787/tcp']);
});

it('does not reconverge a connection update that its outer transaction rolls back', function (): void {
    $gateway = ai_router_gateway();
    $node = ai_router_node();
    $connection = ai_router_valkey($node);
    app(AiRouterState::class)->enable($node->id, $connection->slug, 'http://127.0.0.1:8317', 'management', 'read', 'control');
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $runtime = app(RecordingAiRouterRuntimeLifecycle::class);

    DB::beginTransaction();
    try {
        $this->patchJson('/api/v1/database-connections/'.$connection->slug, ['password' => 'rolled-back'])->assertOk();
        expect($runtime->converged)->toBeFalse();
    } finally {
        DB::rollBack();
    }

    expect($connection->refresh()->password)->toBeNull()
        ->and($runtime->converged)->toBeFalse();
});
