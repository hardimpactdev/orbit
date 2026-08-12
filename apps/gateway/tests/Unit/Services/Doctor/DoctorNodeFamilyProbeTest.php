<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Doctor\DoctorNodeFamilyProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps the two base node checks and their progress order', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'node-family-inactive',
        'status' => 'inactive',
    ]);
    $events = [];

    $issues = app(DoctorNodeFamilyProbe::class)->probe(
        node: $node,
        key: null,
        onFamilyProgress: static function (
            string $family,
            string $phase,
            array $issues,
            ?int $completed,
            ?int $total,
        ) use (&$events): void {
            $events[] = compact('family', 'phase', 'issues', 'completed', 'total');
        },
    );

    expect($issues)->toBeEmpty();
    expect(collect($events)->pluck('family')->all())
        ->toBe(['node', 'node', 'node'])
        ->and(collect($events)->pluck('phase')->all())
        ->toBe(['running', 'running', 'done'])
        ->and(collect($events)->pluck('completed')->all())
        ->toBe([0, 1, null])
        ->and(collect($events)->pluck('total')->all())
        ->toBe([2, 2, null]);
});

it('adds one ordered check for an active s3 role', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'node-family-s3',
        'status' => 'inactive',
        'wireguard_address' => null,
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
        'settings' => ['data_path' => '/srv/orbit/s3/data'],
    ]);
    $events = [];

    $issues = app(DoctorNodeFamilyProbe::class)->probe(
        node: $node,
        key: null,
        onFamilyProgress: static function (
            string $family,
            string $phase,
            array $issues,
            ?int $completed,
            ?int $total,
        ) use (&$events): void {
            $events[] = compact('family', 'phase', 'issues', 'completed', 'total');
        },
    );

    expect(collect($issues)->pluck('key')->all())->toContain('node.s3.wireguard_missing');
    expect(collect($events)->pluck('phase')->all())
        ->toBe(['running', 'running', 'running', 'done'])
        ->and(collect($events)->pluck('completed')->all())
        ->toBe([0, 1, 2, null])
        ->and(collect($events)->pluck('total')->all())
        ->toBe([3, 3, 3, null]);
});
