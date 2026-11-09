<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $make = static fn (string $name, string $ip): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
    ]));
    $this->gateway = $make('gateway', '10.44.0.1');
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->caller = $make('operator', '10.44.0.9');
    $this->caller->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->node = $make('target', '10.44.0.2');
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->url = '/api/v1/nodes/'.$this->node->id.'/roles';
});

it('requires the shared Node access rule for the Metrics target', function (): void {
    $this->postJson($this->url, ['role' => 'metrics'])
        ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');

    expect(Operation::query()->count())->toBe(0)
        ->and(NodeRole::query()->where('node_id', $this->node->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('admits through the target access check without requiring Gateway access for Metrics', function (): void {
    $this->caller->accessibleNodes()->attach($this->node);

    // The deliberately unmanaged target stops admission before native input or a run is built.
    $this->postJson($this->url, ['role' => 'metrics'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.kind_unavailable');

    expect(Operation::query()->count())->toBe(0)
        ->and(NodeRole::query()->where('node_id', $this->node->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('validates the role-add body before creating an assignment or Operation', function (array $input): void {
    $this->caller->accessibleNodes()->attach($this->node);

    $this->postJson($this->url, $input)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect(Operation::query()->count())->toBe(0)
        ->and(NodeRole::query()->where('node_id', $this->node->id)->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'missing role' => [[]],
    'unknown role' => [['role' => 'operator']],
    'non-boolean convergence' => [['role' => 'app-dev', 'converge_existing' => 1]],
    'Analytics requires both IDs even during convergence' => [['role' => 'analytics', 'converge_existing' => true]],
    'Postgres option belongs to Analytics' => [['role' => 'app-dev', 'postgres_process_id' => 41]],
    'ClickHouse option belongs to Analytics' => [['role' => 'metrics', 'clickhouse_process_id' => 42]],
    'storage IDs are strict integers' => [['role' => 'analytics', 'postgres_process_id' => '41', 'clickhouse_process_id' => 42]],
    'storage IDs are positive' => [['role' => 'analytics', 'postgres_process_id' => 0, 'clickhouse_process_id' => 42]],
]);
