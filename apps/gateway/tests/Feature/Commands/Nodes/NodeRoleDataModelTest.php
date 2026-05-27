<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores multiple role assignments with typed status and settings per node', function (): void {
    $node = Node::factory()->create([
        'name' => 'dev-1',
        'role' => 'control',
    ]);

    NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ]);

    expect($node->fresh()->roleAssignments)
        ->toHaveCount(2)
        ->and($node->fresh()->roleAssignments->pluck('role')->all())
        ->toBe(['app-development', 'database']);
});

it('enforces one assignment per role per node', function (): void {
    $node = Node::factory()->create();

    NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ]);

    expect(fn () => NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ]))->toThrow(QueryException::class);
});
