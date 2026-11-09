<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
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

    it('lists a server whose Node was removed only for a caller with Gateway access', function (): void {
        conn_server('env-orphan', null);

        expect(array_column(conn_call($this->phone, 'GET', 'environments')->json('data'), 'environment_id'))->not->toContain('env-orphan');

        conn_call($this->phone, 'GET', 'environments/env-orphan/pairings')->assertStatus(403)
            ->assertJsonPath('error.code', 'node_access.required')
            ->assertJsonPath('error.details.serving_node', null);
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

    it('lets the registering Node, a new server, or a server without a Node reach the T3 check', function (string $environmentId): void {
        conn_server('env-orphan', null);

        conn_call($this->beast, 'POST', 'environments', conn_registration($environmentId))->assertStatus(502)
            ->assertJsonPath('error.code', 'conn.server_unreachable');
    })->with(['own' => 'env-beast', 'new' => 'env-new', 'orphan' => 'env-orphan']);

    it('needs no access edge to register', function (): void {
        conn_call($this->taskVm, 'POST', 'environments', conn_registration('env-new'))->assertStatus(502)
            ->assertJsonPath('error.code', 'conn.server_unreachable');
    });
});
