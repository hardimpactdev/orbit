<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

const HOLD_RUN = 'ea55ab37-bcbe-4459-81dc-cbb397e7d870';

function hold_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit']));
}

/** @return array{0:Node,1:Node} The Swamp Node that calls, and the root Node of the Operations. */
function hold_nodes(): array
{
    $swamp = hold_node('swamp', '10.44.0.15');
    NodeRole::query()->create(['node_id' => $swamp->id, 'role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);

    return [$swamp, hold_node('shark', '10.44.0.11')];
}

function hold_operation(Node $root, string $kind, array $serving, string $run = HOLD_RUN): Operation
{
    $entries = array_map(static fn (int $id): array => ['node_id' => $id, 'serves_routes' => true], $serving);

    return Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $root->id,
        'input' => ['route' => ['route_lifecycle' => ['route_mutations' => ['forward' => ['publications' => $entries]]]]],
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run], 'sequence' => 1, 'attempts' => 1]);
}

function hold_call(Node $caller, Operation $operation, string $action, string $run = HOLD_RUN): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson("/api/v1/direct-operations/{$operation->id}/route-{$action}", ['run_id' => $run]);
}

beforeEach(fn () => config(['operations.route_hold_wait_seconds' => 0]));

it('holds the Nodes of a provision for the Swamp Node and gives them back on release', function (): void {
    [$swamp, $root] = hold_nodes();
    $operation = hold_operation($root, 'tasks.provision', [5, 6]);

    hold_call($swamp, $operation, 'hold')->assertOk()->assertJsonPath('data.held', [5, 6]);
    expect(DB::table('route_publication_holds')->where('operation_id', $operation->id)->count())->toBe(2);

    hold_call($swamp, $operation, 'release')->assertOk()->assertJsonPath('data.released', true);
    expect(DB::table('route_publication_holds')->count())->toBe(0);
});

it('answers 423 route_hold.busy while another Operation holds a Node of the step', function (): void {
    [$swamp, $root] = hold_nodes();
    [$first, $second] = [hold_operation($root, 'tasks.provision', [5]), hold_operation($root, 'tasks.cancel', [5, 6])];

    hold_call($swamp, $first, 'hold')->assertOk();
    hold_call($swamp, $second, 'hold')->assertStatus(423)->assertJsonPath('error.code', 'route_hold.busy');
    expect(DB::table('route_publication_holds')->where('operation_id', $second->id)->count())->toBe(0);
});

it('refuses a kind that holds per Operation, a stranger Node and a run that is not the bound one', function (): void {
    [$swamp, $root] = hold_nodes();
    $stranger = hold_node('stranger', '10.44.0.99');

    hold_call($swamp, hold_operation($root, 'route.create', [5]), 'hold')->assertForbidden()->assertJsonPath('error.code', 'operation.route_hold_forbidden');
    hold_call($stranger, hold_operation($root, 'tasks.provision', [5]), 'hold')->assertForbidden();
    hold_call($swamp, hold_operation($root, 'tasks.provision', [5]), 'hold', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')->assertStatus(409);
});

it('refuses a hold for an Operation that has ended', function (): void {
    [$swamp, $root] = hold_nodes();
    $operation = hold_operation($root, 'tasks.maintenance_finish', [5]);
    $operation->forceFill(['status' => OperationStatus::Succeeded, 'finished_at' => now()])->save();

    hold_call($swamp, $operation, 'hold')->assertStatus(409)->assertJsonPath('error.code', 'operation.finished');
    expect(DB::table('route_publication_holds')->count())->toBe(0);
});
