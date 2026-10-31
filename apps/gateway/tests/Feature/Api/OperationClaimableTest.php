<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\PolledOperationFixture;

beforeEach(function (): void {
    // Shared claim behavior uses fixed test contracts, independent of product writer cutovers.
    $definitions = OperationKinds::definitions();
    foreach (['firewall.allow', 'firewall.deny', 'firewall.remove'] as $kind) {
        $definitions[$kind] = PolledOperationFixture::definition();
    }
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $this->travelTo(Carbon::parse('2026-10-29 14:00:00', 'UTC'));
    $this->gateway = Node::query()->create(['name' => 'gateway', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $this->gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->services = Node::query()->create(['name' => 'services', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'worker', 'wireguard_ip' => '10.44.0.12', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'status' => LifecycleStatus::Active]);
    $this->services->accessibleNodes()->attach($this->gateway);
    $this->worker->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->services->id, 'operation_executor.worker_node_ids' => [$this->worker->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
});

function claimableFixture(OperationStatus $status, string $kind = 'firewall.allow', int $age = 600, int $idle = 0): Operation
{
    $operation = new Operation(['kind' => $kind, 'target_type' => 'node', 'target_id' => 1,
        'status' => $status, 'input' => ['private_fixture' => 'never-return-operation-input'], 'state' => ['executor_lease_node_id' => 987],
        'worker' => $status === OperationStatus::Running ? 'existing-worker' : null]);
    $operation->created_at = Carbon::now()->subSeconds($age);
    $operation->updated_at = Carbon::now()->subSeconds($idle);
    $operation->save();

    return $operation;
}

it('reports only registered Swamp work without exposing records or writing anything', function (): void {
    claimableFixture(OperationStatus::Queued, age: 300);
    claimableFixture(OperationStatus::Running, age: 600, idle: 120);
    claimableFixture(OperationStatus::Running, age: 900, idle: 119);
    foreach ([OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled, OperationStatus::Waiting] as $status) {
        claimableFixture($status, age: 1000, idle: 1000);
    }
    claimableFixture(OperationStatus::Queued, 'instance.deploy');
    claimableFixture(OperationStatus::Queued, 'unknown.kind');
    $before = Operation::query()->orderBy('id')->get()->toArray();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/\A\s*(?:insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    $response = $this->getJson('/api/v1/operation-executor/claimable')->assertOk();
    $kinds = collect($response->json('data.kinds'))->keyBy('kind');
    expect($kinds->only(['firewall.allow', 'firewall.deny', 'firewall.remove'])->all())->toBe([
        'firewall.allow' => ['kind' => 'firewall.allow', 'claimable' => 2, 'oldest_at' => Carbon::now()->subSeconds(600)->toISOString()],
        'firewall.deny' => ['kind' => 'firewall.deny', 'claimable' => 0, 'oldest_at' => null],
        'firewall.remove' => ['kind' => 'firewall.remove', 'claimable' => 0, 'oldest_at' => null],
    ])->and($kinds->has('unknown.kind'))->toBeFalse();
    foreach ($kinds as $kind) {
        expect($kind)->toHaveKeys(['kind', 'claimable', 'oldest_at'])->toHaveCount(3);
    }
    $response->assertDontSee('never-return-operation-input');
    expect($writes)->toBe([])->and(Operation::query()->orderBy('id')->get()->toArray())->toBe($before);
});

it('uses the same candidate set as claims from the placed worker', function (): void {
    $queued = claimableFixture(OperationStatus::Queued);
    $expired = claimableFixture(OperationStatus::Running, idle: 120);
    claimableFixture(OperationStatus::Running, idle: 119);
    $response = $this->getJson('/api/v1/operation-executor/claimable')->assertOk();
    expect(collect($response->json('data.kinds'))->firstWhere('kind', 'firewall.allow')['claimable'])->toBe(2);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'first'])->assertOk()->assertJsonPath('data.id', $queued->id);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'second'])->assertOk()->assertJsonPath('data.id', $expired->id);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'third'])->assertOk()->assertJsonPath('data', null);
    $this->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
    $response = $this->getJson('/api/v1/operation-executor/claimable')->assertOk();
    expect(collect($response->json('data.kinds'))->firstWhere('kind', 'firewall.allow'))->toBe(['kind' => 'firewall.allow', 'claimable' => 0, 'oldest_at' => null]);
});

it('allows only the active configured Services peer with Gateway access', function (string $case): void {
    if ($case === 'worker') {
        $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    } elseif ($case === 'missing setting') {
        config(['dependency_detection.services_node_id' => null]);
    } elseif ($case === 'no access') {
        $this->services->accessibleNodes()->detach($this->gateway);
    } elseif ($case === 'inactive') {
        $this->services->update(['status' => LifecycleStatus::Failed]);
    } else {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99']);
    }
    $this->getJson('/api/v1/operation-executor/claimable')->assertForbidden();
})->with(['worker', 'missing setting', 'no access', 'inactive', 'unknown peer']);
