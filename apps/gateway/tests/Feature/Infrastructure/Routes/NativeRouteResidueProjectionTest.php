<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeFootprintProjection;
use App\Models\Node;
use App\Models\NodeFootprint;
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

it('keeps failed residue retryable and deletes only the rows proven by a later cleanup', function (): void {
    $worker = Node::query()->create(['name' => 'residue-retry', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.31',
        'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $worker->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $residue = RouteRemovalResidue::query()->create([
        'node_id' => $worker->id, 'route_id' => 997, 'domain' => 'retry.orbit',
        'steps' => ['caddy', 'php', 'certificates', 'firewall'], 'attempts' => 0,
    ]);
    $snapshot = ['node_id' => $worker->id,
        'artifacts' => [['name' => 'route-residue', 'digest' => str_repeat('a', 64)]],
        'payloads' => ['route-residue' => app(NodeConvergeInput::class)->residue($worker)]];
    $projection = app(NodeFootprintProjection::class);
    $operation = new Operation(['node_id' => $worker->id]);

    $projection->record($operation, $snapshot, ['footprint_proven' => true],
        ['route-residue' => ['status' => 'skipped', 'reason' => 'route_residue_cleanup_failed']]);

    $this->assertDatabaseHas('route_removal_residues', ['id' => $residue->id, 'attempts' => 1]);
    expect(NodeFootprint::query()->where('node_id', $worker->id)->sole()->artifacts)->toBe(['route-residue' => str_repeat('a', 64)]);

    $retry = ['node_id' => $worker->id,
        'artifacts' => [['name' => 'route-residue', 'digest' => str_repeat('b', 64)]],
        'payloads' => ['route-residue' => app(NodeConvergeInput::class)->residue($worker)]];
    $later = RouteRemovalResidue::query()->create([
        'node_id' => $worker->id, 'route_id' => 998, 'domain' => 'later.orbit',
        'steps' => ['caddy', 'certificates'], 'attempts' => 0,
    ]);

    $projection->record($operation, $retry, ['footprint_proven' => true], ['route-residue' => ['status' => 'applied']]);

    $this->assertModelMissing($residue);
    $this->assertModelExists($later);
    expect(NodeFootprint::query()->where('node_id', $worker->id)->sole()->artifacts)->toBe(['route-residue' => str_repeat('b', 64)]);
});
