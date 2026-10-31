<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeFootprintProjection;
use App\Models\Node;
use App\Models\Operation;
use App\Models\RouteRemovalResidue;

it('captures offline residue and records only the native cleanup result', function (): void {
    $worker = Node::query()->create(['name' => 'worker', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.30',
        'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $worker->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $residue = RouteRemovalResidue::query()->create([
        'node_id' => $worker->id, 'route_id' => 999, 'domain' => 'removed.orbit',
        'steps' => ['caddy', 'php', 'certificates', 'firewall'], 'attempts' => 0,
    ]);
    $input = app(NodeConvergeInput::class)->residue($worker);
    expect($input['rows'])->toHaveCount(1)
        ->and($input['certificates'])->toContain('route-999', 'route-999-router')
        ->and($input['firewall'])->toBe([['operation' => 'remove', 'route_id' => 999]])
        ->and(RouteRemovalResidue::query()->exists())->toBeTrue();
    $snapshot = ['node_id' => $worker->id,
        'artifacts' => [['name' => 'route-residue', 'digest' => str_repeat('a', 64)]],
        'payloads' => ['route-residue' => $input]];
    app(NodeFootprintProjection::class)->record(
        new Operation(['node_id' => $worker->id]), $snapshot, [],
        ['route-residue' => ['status' => 'applied']],
    );
    expect($residue->fresh())->toBeNull();
});
