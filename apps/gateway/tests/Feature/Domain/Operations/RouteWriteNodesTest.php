<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Operations\RouteWriteNodes;
use App\Domain\Routes\RouteDestroyEngineFlow;
use App\Domain\Routes\RouteUpdateEngineFlow;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => Queue::fake());

/** A Route write of `$kind` with `$input`, open; `$engine` binds it to the Gateway engine. */
function routeWriter(string $kind, array $input, bool $engine = false): Operation
{
    $root = Node::query()->where('name', 'root')->first() ?? Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'root', 'status' => 'active',
        'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'route', 'target_id' => $kind === 'route.update' ? 1 : 5, 'node_id' => $root->id,
        'input' => $input, 'status' => $engine ? OperationStatus::Running : OperationStatus::Queued, 'state' => [], 'sequence' => 1, 'attempts' => 0]);
    if ($engine) {
        $operation->forceFill(['state' => ['swamp_run_id' => 'engine-'.$operation->id]])->save();
    }

    return $operation->refresh();
}

/** An engine update's plan that writes Node `$node` in one shape. */
function engineUpdateInput(string $shape, int $node): array
{
    $plan = ['publish' => ['site_nodes' => []], 'retire' => ['site_nodes' => []], 'edge_nodes' => [], 'retire_nodes' => [], 'dns' => [], 'cleanup' => []];
    match ($shape) {
        'serving' => $plan['publish']['site_nodes'] = [$node],
        'edge' => $plan['edge_nodes'] = [$node],
        'retire' => $plan['retire_nodes'] = [$node],
        'dns' => $plan['dns'] = [['node_id' => $node, 'records' => "address=/new.example/10.44.0.2\n", 'catalog' => "{}\n"]],
    };

    return ['route_update' => ['route_id' => 1, 'result_route_id' => 2, 'plan' => $plan]];
}

/** A Swamp Route writer that writes Node `$node` in the same shape, as its own kind's plan carries it. */
function swampWriter(string $shape, int $node): Operation
{
    return match ($shape) {
        'serving' => routeWriter('route.create', ['route_lifecycle' => ['route_mutations' => ['forward' => ['publications' => [['node_id' => $node, 'serves_routes' => true]]]]]]),
        'edge' => routeWriter('route.update', ['route_update' => ['route_id' => 3, 'result_route_id' => 4, 'plan' => ['edge_nodes' => [$node]]]]),
        'retire' => routeWriter('route.target_set', ['route_target' => ['plan' => ['retire_nodes' => [$node]]]]),
        'dns' => routeWriter('route.destroy', ['route_removal' => ['dns_node_ids' => [$node]]]),
    };
}

$shapes = ['a serving Node' => ['serving'], 'an edge Node' => ['edge'], 'a retired Node' => ['retire'], 'a DNS-only VPN Node' => ['dns']];

it('reads every Node a write changes from its plans', function (): void {
    expect(RouteWriteNodes::of(['route_update' => ['plan' => ['publish' => ['site_nodes' => [3]], 'edge_nodes' => [6], 'retire_nodes' => [4],
        'dns' => [['node_id' => 1]]]], 'route_lifecycle' => ['publications' => [['node_id' => 5, 'serves_routes' => true], ['node_id' => 9, 'serves_routes' => false]]],
        'route_removal' => ['dns_node_ids' => [2]]]))->toBe([1, 2, 3, 4, 5, 6]);
});

it('refuses a Swamp Route write while an open engine update writes one of its Nodes, before it holds or dispatches', function (string $shape): void {
    routeWriter('route.update', engineUpdateInput($shape, 8), engine: true);
    $swamp = swampWriter($shape, 8);

    expect(fn () => RouteWriteNodes::refuseBesideEngine($swamp))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_held'))
        ->and($swamp->refresh()->status)->toBe(OperationStatus::Failed)
        ->and(DB::table('route_publication_holds')->count())->toBe(0);
})->with($shapes);

it('refuses an engine update\'s start while an open Swamp Route write writes one of its Nodes', function (string $shape): void {
    swampWriter($shape, 8);
    $engine = routeWriter('route.update', engineUpdateInput($shape, 8));

    expect(fn () => RouteUpdateEngineFlow::holdNodes($engine))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_held'));
})->with($shapes);

it('lets writes on other Nodes through on both sides', function (string $shape): void {
    routeWriter('route.update', engineUpdateInput($shape, 8), engine: true);
    RouteWriteNodes::refuseBesideEngine(swampWriter($shape, 9));
    RouteUpdateEngineFlow::holdNodes(routeWriter('route.update', engineUpdateInput($shape, 7)));
})->with($shapes)->throwsNoExceptions();

it('keeps a Swamp phase writer out of the Node holds, as on main, so it is never queued behind another write', function (): void {
    expect(RoutePublicationHolds::nodes(swampWriter('edge', 8)))->toBe([])
        ->and(RoutePublicationHolds::nodes(swampWriter('retire', 8)))->toBe([]);
});

it('refuses an engine update\'s start while an Ingress relocation is open anywhere, as its Nodes are known only at its step', function (): void {
    routeWriter('node.role_relocate', ['_relocate' => ['role' => 'ingress']]);

    expect(fn () => RouteUpdateEngineFlow::holdNodes(routeWriter('route.update', engineUpdateInput('serving', 8))))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_held'));
});

it('refuses an Ingress relocation or a Router change while any engine update is open', function (string $kind): void {
    routeWriter('route.update', engineUpdateInput('dns', 8), engine: true);
    $late = routeWriter($kind, []);

    expect(fn () => RouteWriteNodes::refuseBesideEngine($late))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_held'))
        ->and($late->refresh()->status)->toBe(OperationStatus::Failed);
})->with(['an Ingress relocation' => ['node.role_relocate'], 'a Router change' => ['cluster.router_set']]);

it('treats a timed-out Swamp update whose run is not proven stopped as still writing its Nodes', function (string $shape): void {
    $expired = swampWriter($shape === 'serving' ? 'serving' : 'dns', 8);
    $expired->forceFill(['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out', 'finished_at' => now(),
        'state' => ['native_timeout' => ['budget_seconds' => 60, 'deadline' => now()->toISOString()]]])->save();
    $engine = routeWriter('route.update', engineUpdateInput($shape, 8));

    expect(fn () => RouteUpdateEngineFlow::holdNodes($engine))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_held'));

    $expired->forceFill(['state' => [...$expired->state, 'native_cancel_confirmed_at' => now()->toISOString()]])->save();
    RouteUpdateEngineFlow::holdNodes($engine);
})->with(['a serving Node' => ['serving'], 'a VPN-only Node' => ['dns']]);

it('fences an engine route.destroy against every other Route write on its serving and VPN Nodes, both ways', function (string $shape): void {
    $removal = ['route_removal' => ['dns_node_ids' => $shape === 'dns' ? [8] : []],
        'route_lifecycle' => ['plan' => ['retire_nodes' => $shape === 'dns' ? [] : [8], 'dns' => []]]];
    routeWriter('route.destroy', $removal, engine: true);
    $swamp = routeWriter('route.update', engineUpdateInput($shape === 'dns' ? 'dns' : 'retire', 8));

    expect(fn () => RouteWriteNodes::refuseBesideEngine($swamp))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_held'))
        ->and(fn () => RouteDestroyEngineFlow::holdNodes(routeWriter('route.destroy', $removal)))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_held'));
})->with(['a serving Node' => ['retire'], 'a DNS-only VPN Node' => ['dns']]);
