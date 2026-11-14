<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeDesiredConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\SwampRoleUnits;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** The agent pin the Gateway delivers: one manifest file per test, so a test can move the pin. */
function digestManifest(string $sha256): string
{
    $path = tempnam(sys_get_temp_dir(), 'agent-manifest-');
    file_put_contents($path, json_encode(['commit' => str_repeat('a', 40), 'version' => '1.2.3',
        'artifacts' => ['linux-x86_64' => ['sha256' => $sha256, 'url' => 'https://artifacts.example/agent']]], JSON_THROW_ON_ERROR));

    return $path;
}

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    // The command builds an intent only: an execution copy or a run would go to Swamp through this socket.
    $this->mock(WebSocketClient::class, fn ($mock) => $mock->shouldNotReceive('connect', 'send', 'receive'));
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $this->manifest = digestManifest(str_repeat('b', 64));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, '/unused'));
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.45.0.1', 'public_ssh_host' => '10.45.0.1', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-key']));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $control->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.45.0.2', 'public_ssh_host' => '10.45.0.2', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'app-dev-key']));
    $this->node->forceFill(['agent_secret_hash' => hash('sha256', 'private-test-agent-secret')])->save();
});

afterEach(function (): void {
    @unlink($this->manifest);
});

/** @return array<string,string> */
function convergeDigests(object $test, string ...$names): array
{
    $test->artisan('orbit:node-converge-digest', ['nodes' => $names, '--base' => true])->assertSuccessful();
    $digests = [];
    foreach ($names as $name) {
        $digests[$name] = app(NodeDesiredConvergeInput::class)->baseDigest(Node::query()->where('name', $name)->sole());
    }

    return $digests;
}

it('prints one digest per named Node and writes nothing: no Operation, no execution copy, no row', function (): void {
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    $expected = json_encode(['gateway' => app(NodeDesiredConvergeInput::class)->baseDigest(Node::query()->where('name', 'gateway')->sole()),
        'app-dev' => app(NodeDesiredConvergeInput::class)->baseDigest($this->node)], JSON_THROW_ON_ERROR);

    $this->artisan('orbit:node-converge-digest', ['nodes' => ['gateway', 'app-dev'], '--base' => true])
        ->expectsOutput($expected)->assertSuccessful();

    expect(json_decode($expected, true))->each->toMatch('/^[0-9a-f]{64}$/')
        ->and($writes)->toBe([])->and(Operation::query()->count())->toBe(0)
        ->and($expected)->not->toContain(hash('sha256', 'private-test-agent-secret'));
    Http::assertNothingSent();
    Process::assertNothingRan();
});

it('gives the same digest twice, seconds apart, and the intent is the base intent of a converge', function (): void {
    // A Node with roles still has a base intent without roles, Tools or a network: the refresh's cut includes stay unreached.
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->node->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $first = convergeDigests($this, 'app-dev');
    $this->travel(47)->seconds();
    $intent = app(NodeDesiredConvergeInput::class)->baseIntent($this->node);

    expect(convergeDigests($this, 'app-dev'))->toBe($first)
        ->and($intent['roles'])->toBe([])->and($intent['selected_roles'])->toBe([])
        ->and($intent['tools'])->toBe([])->and($intent['network'])->toBeNull()
        ->and($intent['agent'])->toMatchArray(['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent']);
});

it('moves when an input of the base converge moves: the agent pin or the Node user', function (): void {
    $before = convergeDigests($this, 'app-dev')['app-dev'];
    $moved = digestManifest(str_repeat('c', 64));
    app()->instance(AgentDelivery::class, new AgentDelivery($moved, '/unused'));
    $pin = convergeDigests($this, 'app-dev')['app-dev'];
    Node::withoutEvents(fn () => $this->node->forceFill(['user' => 'deploy'])->save());
    $user = convergeDigests($this, 'app-dev')['app-dev'];
    @unlink($moved);

    expect($pin)->not->toBe($before)->and($user)->not->toBe($pin);
});

it('fails without --base, and for an unknown Node, with no digest printed', function (): void {
    $this->artisan('orbit:node-converge-digest', ['nodes' => ['app-dev']])->assertFailed();
    $this->artisan('orbit:node-converge-digest', ['nodes' => ['app-dev', 'missing'], '--base' => true])
        ->expectsOutputToContain('The Node missing is unknown.')->doesntExpectOutputToContain('app-dev":')->assertFailed();
});

it('carries the units of the Node roles in the base intent, never the serve unit, so the digest moves when their text does', function (): void {
    $gateway = Node::query()->where('name', 'gateway')->sole();
    $gateway->roles()->create(['role' => RoleName::WebSocket, 'status' => 'active']);
    $units = app(NodeDesiredConvergeInput::class)->baseIntent($gateway)['role_units'];

    expect(array_column($units, 'name'))->toBe(['orbit-swamp-worker@.service', 'orbit-websocket.service'])
        ->and($units[0]['content'])->toBe(SwampRoleUnits::for('orbit', $gateway->id)['worker'])
        ->and($units[1]['content'])->toContain('Description=Orbit websocket role: Reverb realtime server')
        ->and(array_column($units, 'name'))->not->toContain('orbit-swamp-serve.service')
        // A Node without these roles carries none.
        ->and(app(NodeDesiredConvergeInput::class)->baseIntent($this->node)['role_units'])->toBe([]);

    $before = app(NodeDesiredConvergeInput::class)->baseDigest($gateway);
    expect(app(NodeDesiredConvergeInput::class)->baseDigest($gateway))->toBe($before);
    config(['orbit.websocket.port' => (int) config('orbit.websocket.port') + 1]);
    expect(app(NodeDesiredConvergeInput::class)->baseDigest($gateway))->not->toBe($before);
});
