<?php

declare(strict_types=1);

use App\Http\Authorization\ServingNode;
use App\Http\Authorization\ServingNodeResolver;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

it('resolves deleted runtime targets only through a correctly typed matching receipt', function (string $family, string $field, string $selector, mixed $recordedId, bool $matches): void {
    $node = Node::query()->create(['name' => 'runtime-receipt-owner', 'platform' => 'linux', 'status' => 'active',
        'user' => 'orbit', 'public_ssh_host' => '192.0.2.18']);
    Operation::query()->create(['kind' => $family.'.destroy', 'target_type' => $family === 'process' ? 'process' : 'node_schedules',
        'target_id' => $family === 'process' ? 42 : $node->id, 'node_id' => $node->id, 'status' => 'succeeded',
        'idempotency_key' => 'deleted-runtime', 'input' => [$field => $recordedId]]);
    $request = Request::create('/'.$family.'/'.$selector, 'DELETE');
    $request->headers->set('Idempotency-Key', 'deleted-runtime');
    $route = (new Route(['DELETE'], '/'.$family.'/{'.$family.'}', static fn (): null => null))->name($family.':destroy');
    $route->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);

    $nodes = app(ServingNodeResolver::class)->resolve($request, $family === 'process' ? ServingNode::ProcessOwning : ServingNode::ScheduleOwning);

    expect(array_map(static fn (Node $node): int => $node->id, $nodes))->toBe($matches ? [$node->id] : []);
})->with([
    'Process numeric ID' => ['process', 'process_id', '42', 42, true],
    'Process wrong ID' => ['process', 'process_id', '42', 43, false],
    'Process string ID' => ['process', 'process_id', '42', '42', false],
    'Process malformed ID' => ['process', 'process_id', '42', ['42'], false],
    'Schedule UUID' => ['schedule', 'id', '830fd68c-948b-4e46-a215-229c7f510246', '830fd68c-948b-4e46-a215-229c7f510246', true],
    'Schedule wrong UUID' => ['schedule', 'id', '830fd68c-948b-4e46-a215-229c7f510246', '4be31ead-330f-45a4-993f-d12cd927fbb0', false],
    'Schedule malformed ID' => ['schedule', 'id', '830fd68c-948b-4e46-a215-229c7f510246', ['830fd68c-948b-4e46-a215-229c7f510246'], false],
]);
