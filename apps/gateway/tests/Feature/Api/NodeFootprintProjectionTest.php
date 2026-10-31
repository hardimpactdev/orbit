<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeFootprintProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeFootprint;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

it('retains other digests when an optional footprint partially fails inside a successful parent', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('role-parent');
    NodeFootprint::query()->create(['node_id' => $node->id, 'artifacts' => ['other' => 'retained', 'agent' => 'old', 'tmpfiles' => 'old']]);
    $operation = new Operation(['node_id' => $node->id, 'status' => 'succeeded']);
    $snapshot = ['node_id' => $node->id, 'agent_sha256' => str_repeat('a', 64), 'artifacts' => [
        ['name' => 'agent', 'digest' => str_repeat('b', 64)], ['name' => 'tmpfiles', 'digest' => str_repeat('c', 64)],
    ]];

    app(NodeFootprintProjection::class)->record($operation, $snapshot, ['footprint_proven' => false, 'failed_artifact' => 'tmpfiles'],
        ['agent' => ['name' => 'agent', 'status' => 'unchanged']]);

    expect(NodeFootprint::query()->where('node_id', $node->id)->sole()->artifacts)
        ->toBe(['agent' => str_repeat('b', 64), 'other' => 'retained']);
});

it('refuses foreign Node footprint projection without creating a record', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('root-owner');
    $operation = new Operation(['node_id' => $node->id, 'status' => 'succeeded']);
    expect(fn () => app(NodeFootprintProjection::class)->record($operation, ['node_id' => $node->id + 1], [], []))
        ->toThrow(ResourceOperationException::class);
    expect(NodeFootprint::query()->count())->toBe(0);
});
