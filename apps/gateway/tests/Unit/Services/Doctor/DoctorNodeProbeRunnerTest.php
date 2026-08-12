<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Doctor\DoctorNodeProbeRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('runs selected families in canonical order while preserving the selected scope', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'doctor-coordinator-order',
        'status' => 'inactive',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    $events = [];

    $report = app(DoctorNodeProbeRunner::class)->probe(
        node: $node,
        families: ['tool', 'node'],
        onFamilyProgress: static function (string $family) use (&$events): void {
            $events[] = $family;
        },
    );

    expect(array_values(array_unique($events)))
        ->toBe(['node', 'tool'])
        ->and($report['scope']['families'])
        ->toBe(['tool', 'node'])
        ->and($report)
        ->toHaveKeys(['healthy', 'mode', 'scope', 'summary', 'issues', 'actions'])
        ->and($report['mode'])
        ->toBe('verify')
        ->and($report['actions'])
        ->toBeEmpty();
});
