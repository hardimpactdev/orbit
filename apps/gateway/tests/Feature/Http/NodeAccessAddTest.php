<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

function accessAddNode(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip,
    ]));
}

function accessAddSnapshot(): array
{
    return collect(['nodes', 'node_roles', 'node_access', 'task_vms', 'operations'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Process::fake();
    Queue::fake();
    $this->gateway = accessAddNode('gateway', '10.44.0.1');
    $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $this->consumer = accessAddNode('consumer', '10.44.0.2');
    $this->serving = accessAddNode('serving', '10.44.0.3');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->url = "/api/v1/nodes/{$this->serving->id}/access/{$this->consumer->id}";
});

afterEach(function (): void {
    Http::assertNothingSent();
    Process::assertNothingRan();
    Queue::assertNothingPushed();
    expect(Operation::query()->count())->toBe(0);
});

it('records exactly one directed grant and preserves unrelated records for every Gateway authority', function (string $authority): void {
    $caller = accessAddNode('operator', '10.44.0.9');
    if ($authority === 'gateway-grant') {
        $caller->accessibleNodes()->attach($this->gateway);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    } elseif ($authority === 'serving-host') {
        app(GatewayServingHost::class)->remember($caller);
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    }
    $this->serving->accessibleNodes()->attach($this->consumer);
    $before = accessAddSnapshot();
    $grants = NodeAccess::query()->orderBy('id')->get()->toArray();

    $this->putJson($this->url)->assertOk()->assertExactJsonStructure([
        'data' => ['consumer_node' => ['id', 'name'], 'serving_node' => ['id', 'name'], 'already_exists'],
        'meta' => ['request_id'],
    ])->assertJsonPath('data', [
        'consumer_node' => ['id' => $this->consumer->id, 'name' => 'consumer'],
        'serving_node' => ['id' => $this->serving->id, 'name' => 'serving'],
        'already_exists' => false,
    ]);

    $this->assertDatabaseHas('node_access', [
        'consumer_node_id' => $this->consumer->id, 'serving_node_id' => $this->serving->id,
    ]);
    expect(NodeAccess::query()->count())->toBe(count($grants) + 1);
    expect(NodeAccess::query()->whereIn('id', array_column($grants, 'id'))->orderBy('id')->get()->toArray())->toBe($grants);
    expect(array_diff_key(accessAddSnapshot(), ['node_access' => true]))->toBe(array_diff_key($before, ['node_access' => true]));
})->with(['gateway-peer', 'gateway-grant', 'serving-host']);

it('returns an existing grant without writing relevant records', function (): void {
    $this->consumer->accessibleNodes()->attach($this->serving);
    $before = accessAddSnapshot();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->putJson($this->url)->assertOk()->assertJsonPath('data.already_exists', true);

    $writes = array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1
        && ! str_contains($query['query'], '"activity_log"'));
    expect($writes)->toBe([]);
    expect(accessAddSnapshot())->toBe($before);
});

it('returns 403 for target-only access or no authority without changing records', function (bool $targetGrant): void {
    if ($targetGrant) {
        $this->consumer->accessibleNodes()->attach($this->serving);
    }
    $this->withServerVariables(['REMOTE_ADDR' => $this->consumer->wireguard_ip]);
    $before = accessAddSnapshot();

    $this->putJson($this->url)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');

    expect(accessAddSnapshot())->toBe($before);
})->with([false, true]);

it('returns 404 for either unknown or inactive endpoint without changing records', function (string $endpoint, ?string $status): void {
    if ($status !== null) {
        $this->{$endpoint}->updateQuietly(['status' => $status]);
    }
    $consumer = $endpoint === 'consumer' && $status === null ? 2147483647 : $this->consumer->id;
    $serving = $endpoint === 'serving' && $status === null ? 2147483647 : $this->serving->id;
    $before = accessAddSnapshot();

    $this->putJson("/api/v1/nodes/{$serving}/access/{$consumer}")
        ->assertNotFound()->assertJsonPath('error.code', 'http.404');

    expect(accessAddSnapshot())->toBe($before);
})->with([
    ['consumer', null], ['serving', null], ['consumer', 'failed'], ['serving', 'failed'],
    ['consumer', 'provisioning'], ['serving', 'provisioning'], ['consumer', 'removing'], ['serving', 'removing'],
]);

it('refuses a task VM consumer even for a pre-existing grant without changing any record', function (bool $existing): void {
    if ($existing) {
        $this->consumer->accessibleNodes()->attach($this->serving);
    }
    TaskVm::query()->create([
        'node_id' => $this->consumer->id, 'provider' => 'incus', 'name' => 'access-worker',
        'wireguard_ip' => $this->consumer->wireguard_ip, 'state' => 'ready',
    ]);
    $before = accessAddSnapshot();

    $this->putJson($this->url)->assertStatus(409)->assertJsonPath('error.code', 'task_vm.access_refused');

    expect(accessAddSnapshot())->toBe($before);
})->with([false, true]);
