<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Operations\OperationStatus;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ApiOperationTool;
use App\Http\Mcp\ToolManifest;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request as McpRequest;
use Orbit\Extensions\Conn\Domain\T3AdminSession;
use Orbit\Extensions\Conn\Domain\T3EnvironmentDescriptor;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Domain\T3ServerException;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfile;

uses(LazilyRefreshDatabase::class);

function conn_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip,
        'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

function conn_server(string $environmentId, ?Node $node): ConnEnvironment
{
    return ConnEnvironment::query()->create([
        'environment_id' => $environmentId, 'label' => $environmentId, 'url' => 'http://10.44.0.30:3773',
        'node_id' => $node?->id, 'admin_session' => 'admin-token', 'admin_session_expires_at' => Carbon::now()->addDays(30),
        'registered_at' => Carbon::now(),
    ]);
}

function conn_clone(Node $node, string $name): ConnEnvironment
{
    $project = Project::query()->create(['name' => ucfirst($name), 'slug' => $name, 'repository_url' => "https://github.com/nckrtl/{$name}.git", 'default_branch' => 'main', 'root' => '.']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/srv/workspace/'.$name]);
    $server = conn_server('env-'.$name, $node);
    $server->forceFill(['instance_id' => $instance->id])->save();

    return $server;
}

/** @param array{domain: string, status: string, web_root?: string} $attributes */
function conn_route(ConnEnvironment $server, array $attributes): void
{
    $route = Route::query()->create(['project_id' => $server->instance->project_id, 'node_id' => $server->instance->node_id,
        'provenance' => 'explicit', 'publication' => 'private', ...$attributes, 'status' => 'pending']);
    $server->instance->routes()->attach($route->id, ['position' => 0]);
    $route->update(['status' => $attributes['status']]);
}

/** A Node with an edge to the active Gateway Node has Gateway access: it reaches every Node. */
function conn_operator(): Node
{
    $gateway = conn_node('gateway', '10.44.0.2');
    NodeRole::withoutEvents(fn () => $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']));
    $operator = conn_node('orbit-ops', '10.44.0.5');
    $operator->accessibleNodes()->attach($gateway->id);

    return $operator;
}

/** @param array<string, mixed> $body */
function conn_call(Node $caller, string $method, string $path, array $body = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->json($method, '/api/v1/conn/'.$path, $body);
}

beforeEach(function (): void {
    app(ExtensionStore::class)->set('conn', true);
    $this->beast = conn_node('beast', '10.44.0.7');
    $this->mini = conn_node('mini', '10.44.0.9');
    $this->phone = conn_node('phone', '10.44.0.77');
    $this->taskVm = conn_node('beast-tvm-1', '10.44.0.120');
    $this->phone->accessibleNodes()->attach($this->beast->id);
    $this->onBeast = conn_server('env-beast', $this->beast);
    $this->onMini = conn_server('env-mini', $this->mini);
});

describe('servers', function (): void {
    it('lists only the servers whose registering Node the caller has access to', function (): void {
        $response = conn_call($this->phone, 'GET', 'environments')->assertOk();

        expect(array_column($response->json('data'), 'environment_id'))->toBe(['env-beast']);
    });

    it('uses the Instance URL rule instead of the T3 server URL', function (array $routes, ?string $url): void {
        $server = conn_clone($this->beast, 'tool');
        foreach ($routes as $attributes) {
            conn_route($server, $attributes);
        }

        $data = collect(conn_call($this->phone, 'GET', 'environments')->assertOk()->json('data'))->keyBy('environment_id');

        expect($data['env-tool']['instance_url'])->toBe($url)
            ->and($data['env-tool']['url'])->toBe('http://10.44.0.30:3773');
    })->with([
        'no Route' => [[], null],
        'first Route when none is authoritative' => [[['domain' => 'pending.orbit', 'status' => 'pending']], 'https://pending.orbit'],
        'authoritative over pending' => [[['domain' => 'pending.orbit', 'status' => 'pending', 'web_root' => 'public'], ['domain' => 'active.orbit', 'status' => 'active']], 'https://active.orbit'],
        'own Route over a web root Route' => [[['domain' => 'web-root.orbit', 'status' => 'active', 'web_root' => 'public'], ['domain' => 'own.orbit', 'status' => 'active']], 'https://own.orbit'],
    ]);

    it('keeps the list query count flat as distinct clone environments grow', function (): void {
        $first = conn_clone($this->beast, 'first');
        conn_route($first, ['domain' => 'first.orbit', 'status' => 'active']);
        conn_call($this->phone, 'GET', 'environments')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        conn_call($this->phone, 'GET', 'environments')->assertOk()->assertJsonCount(2, 'data');
        $smallCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($index = 1; $index <= 8; $index++) {
            $server = conn_clone($this->beast, 'clone-'.$index);
            conn_route($server, ['domain' => "clone-{$index}.orbit", 'status' => 'active']);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        conn_call($this->phone, 'GET', 'environments')->assertOk()->assertJsonCount(10, 'data');
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($largeCount)->toBe($smallCount);
    });

    it('returns clone Orbit facts through the generated conn-environment-list MCP tool', function (): void {
        $server = conn_clone($this->beast, 'tool');
        conn_route($server, ['domain' => 'feature.orbit', 'status' => 'active']);
        $definition = collect(ToolManifest::default()->definitions())->firstWhere('name', 'conn-environment-list');
        $tool = new ApiOperationTool($definition, app(ApiDispatcher::class));
        $caller = HttpRequest::create('/mcp', 'POST', server: ['REMOTE_ADDR' => $this->phone->wireguard_ip]);

        $response = $tool->handle(new McpRequest, $caller);
        $data = collect(json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR)['data'])->keyBy('environment_id');

        expect($data['env-tool'])->toMatchArray([
            'instance_id' => $server->instance_id, 'project_id' => $server->instance->project_id,
            'project_name' => 'Tool', 'project_slug' => 'tool', 'instance_name' => 'feature',
            'node_name' => 'beast', 'instance_url' => 'https://feature.orbit',
        ]);
    });

    it('lists a server whose Node was removed only for a caller with Gateway access', function (): void {
        conn_server('env-orphan', null);

        expect(array_column(conn_call($this->phone, 'GET', 'environments')->json('data'), 'environment_id'))->not->toContain('env-orphan');

        conn_call($this->phone, 'GET', 'environments/env-orphan/pairings')->assertStatus(403)
            ->assertJsonPath('error.code', 'node_access.required')
            ->assertJsonPath('error.details.serving_node', null);

        $operator = conn_operator();
        expect(array_column(conn_call($operator, 'GET', 'environments')->json('data'), 'environment_id'))
            ->toBe(['env-beast', 'env-mini', 'env-orphan']);
        conn_call($operator, 'GET', 'environments/env-orphan/pairings')->assertOk();
    });

    it('lets a caller with access read its pairing and revoke sessions of another Node on that server', function (): void {
        $pairing = ConnPairing::query()->create([
            'node_id' => $this->phone->id, 'conn_environment_id' => $this->onBeast->id, 'pairing_link_id' => 'link-2',
            'client_label' => 'phone via Orbit def', 'expires_at' => Carbon::now()->addMinutes(5), 'credential' => 'secret',
        ]);

        // Past the access check, an unfinished pairing answers 409 and keeps its credential.
        conn_call($this->phone, 'GET', "environments/env-beast/pairings/{$pairing->id}")->assertStatus(409);
        expect($pairing->fresh()?->credential)->toBe('secret');

        $revoke = conn_call($this->phone, 'POST', 'environments/env-beast/pairings/revoke', ['node_id' => $this->mini->id]);
        expect($revoke->status())->not->toBe(403);
    });

    it('reads the pairings of a server the caller has access to', function (): void {
        conn_call($this->phone, 'GET', 'environments/env-beast/pairings')->assertOk()->assertJsonPath('data', []);
    });

    it('refuses pairing calls on a server of a Node the caller has no access to', function (string $method, string $path): void {
        conn_call($this->phone, $method, $path)->assertStatus(403)
            ->assertJsonPath('error.code', 'node_access.required')
            ->assertJsonPath('error.details.consumer_node.name', 'phone')
            ->assertJsonPath('error.details.serving_node.name', 'mini');
    })->with([
        'list' => ['GET', 'environments/env-mini/pairings'],
        'create' => ['POST', 'environments/env-mini/pairings'],
        'revoke' => ['POST', 'environments/env-mini/pairings/revoke'],
    ]);

    it('refuses to hand out a pairing URL after access to the server Node is gone', function (): void {
        $pairing = ConnPairing::query()->create([
            'node_id' => $this->phone->id, 'conn_environment_id' => $this->onMini->id, 'pairing_link_id' => 'link-1',
            'client_label' => 'phone via Orbit abc', 'expires_at' => Carbon::now()->addMinutes(5), 'credential' => 'secret',
        ]);

        conn_call($this->phone, 'GET', "environments/env-mini/pairings/{$pairing->id}")->assertStatus(403)
            ->assertJsonPath('error.code', 'node_access.required');
        expect($pairing->fresh()?->credential)->toBe('secret');
    });

    it('refuses every Conn endpoint except registration to a Node without access edges, such as a task VM', function (string $method, string $path): void {
        ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);
        Operation::query()->forceCreate(['kind' => 'conn.pairing_revoke', 'target_type' => 'conn_pairing_node', 'target_id' => $this->taskVm->id,
            'node_id' => $this->beast->id, 'input' => ['caller_node_id' => $this->taskVm->id], 'state' => [], 'status' => OperationStatus::Queued,
            'sequence' => 1, 'attempts' => 0]);

        conn_call($this->taskVm, $method, $path)->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
    })->with([
        'me' => ['GET', 'me'],
        'bind' => ['PUT', 'me/profile'],
        'profiles' => ['GET', 'profiles'],
        'create profile' => ['POST', 'profiles'],
        'settings' => ['GET', 'profiles/1/settings'],
        'patch settings' => ['PATCH', 'profiles/1/settings'],
        'replace settings' => ['PUT', 'profiles/1/settings'],
        'cleanup' => ['POST', 'environments/env-beast/sessions/cleanup'],
        'environments' => ['GET', 'environments'],
        'pairings' => ['GET', 'environments/env-beast/pairings'],
        'pair' => ['POST', 'environments/env-beast/pairings'],
        'revoke' => ['POST', 'environments/env-beast/pairings/revoke'],
        'operation' => ['GET', 'operations/1'],
        'cancel' => ['POST', 'operations/1/cancel'],
    ]);
});

describe('profiles', function (): void {
    it('lets a Node with any access edge use profiles', function (): void {
        ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);

        conn_call($this->phone, 'GET', 'me')->assertOk()->assertJsonPath('data.name', 'phone');
        conn_call($this->phone, 'GET', 'profiles')->assertOk()->assertJsonPath('data.0.name', 'Nick');
    });
});

describe('registration', function (): void {
    beforeEach(function (): void {
        app()->instance(T3ServerClient::class, new class implements T3ServerClient
        {
            public function describe(string $baseUrl): T3EnvironmentDescriptor
            {
                throw T3ServerException::unreachable($baseUrl);
            }

            public function session(string $baseUrl, string $adminSession): T3AdminSession
            {
                throw T3ServerException::unreachable($baseUrl);
            }

            public function shell(string $baseUrl, string $adminSession): array
            {
                throw T3ServerException::unreachable($baseUrl);
            }
        });
    });

    /** @return array<string, string> */
    function conn_registration(string $environmentId): array
    {
        return ['environment_id' => $environmentId, 'label' => 'spike', 'url' => 'http://10.44.0.120:3773', 'admin_session' => 'token'];
    }

    it('refuses a Node that registers a server another Node registered', function (): void {
        conn_call($this->taskVm, 'POST', 'environments', conn_registration('env-beast'))->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.environment_owned');
        expect($this->onBeast->fresh()?->node_id)->toBe($this->beast->id)
            ->and($this->onBeast->fresh()?->url)->toBe('http://10.44.0.30:3773');
    });

    it('refuses a Node that registers the T3 server of a container Instance itself', function (): void {
        $project = Project::query()->create(['name' => 'hauzer', 'slug' => 'hauzer', 'repository_url' => 'https://github.com/nckrtl/hauzer.git', 'default_branch' => 'main', 'root' => 'public']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id, 'name' => 'feature', 'checkout_path' => '/srv/workspace']);
        $this->onBeast->forceFill(['instance_id' => $instance->id])->save();

        conn_call($this->beast, 'POST', 'environments', conn_registration('env-beast'))->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.environment_owned');
        expect($this->onBeast->fresh()?->url)->toBe('http://10.44.0.30:3773')
            ->and($this->onBeast->fresh()?->instance?->is($instance))->toBeTrue();
    });

    it('lets the registering Node, or anyone for a new server, reach the T3 check', function (string $environmentId): void {
        conn_call($this->beast, 'POST', 'environments', conn_registration($environmentId))->assertStatus(502)
            ->assertJsonPath('error.code', 'conn.server_unreachable');
    })->with(['own' => 'env-beast', 'new' => 'env-new']);

    it('lets only a Node with Gateway access take a server whose Node was removed', function (): void {
        conn_server('env-orphan', null);

        conn_call($this->beast, 'POST', 'environments', conn_registration('env-orphan'))->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.environment_owned');
        conn_call(conn_operator(), 'POST', 'environments', conn_registration('env-orphan'))->assertStatus(502)
            ->assertJsonPath('error.code', 'conn.server_unreachable');
    });

    it('refuses a registration when another Node registered the same id while the T3 server was checked', function (): void {
        $beast = $this->beast;
        app()->instance(T3ServerClient::class, new class($beast) implements T3ServerClient
        {
            public function __construct(private readonly Node $beast) {}

            public function describe(string $baseUrl): T3EnvironmentDescriptor
            {
                conn_server('env-new', $this->beast);

                return new T3EnvironmentDescriptor('env-new', 'spike', '0.9.1');
            }

            public function session(string $baseUrl, string $adminSession): T3AdminSession
            {
                return new T3AdminSession($adminSession, CarbonImmutable::now()->addDays(30), ['access:read', 'access:write']);
            }

            public function shell(string $baseUrl, string $adminSession): array
            {
                return ['threads' => [], 'archivedThreads' => []];
            }
        });

        conn_call($this->taskVm, 'POST', 'environments', conn_registration('env-new'))->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.environment_changed');
        expect(ConnEnvironment::query()->where('environment_id', 'env-new')->sole()->node_id)->toBe($this->beast->id);
    });

    it('needs no access edge to register', function (): void {
        conn_call($this->taskVm, 'POST', 'environments', conn_registration('env-new'))->assertStatus(502)
            ->assertJsonPath('error.code', 'conn.server_unreachable');
    });
});
