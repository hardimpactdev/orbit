<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Doctor\DoctorFleetNodeProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('orders completed fleet results by target order while skipping gaps', function (): void {
    $alpha = Node::factory()->make(['name' => 'alpha']);
    $bravo = Node::factory()->make(['name' => 'bravo']);
    $charlie = Node::factory()->make(['name' => 'charlie']);
    assert($alpha instanceof Node, description: 'The alpha factory must produce a node.');
    assert($bravo instanceof Node, description: 'The bravo factory must produce a node.');
    assert($charlie instanceof Node, description: 'The charlie factory must produce a node.');
    /** @var Illuminate\Support\Collection<int, Node> $targets */
    $targets = collect([$alpha, $bravo, $charlie]);
    $projection = app(DoctorFleetNodeProjection::class);

    $nodes = $projection->orderedNodeSummaries($targets, [
        2 => ['node' => 'charlie'],
        0 => ['node' => 'alpha'],
    ]);
    $issues = $projection->orderedIssues($targets, [
        2 => [['node' => 'charlie', 'key' => 'node.charlie_issue']],
        0 => [['node' => 'alpha', 'key' => 'node.alpha_issue']],
    ]);

    expect($nodes)
        ->toBe([
            ['node' => 'alpha'],
            ['node' => 'charlie'],
        ])
        ->and($issues)
        ->toBe([
            ['node' => 'alpha', 'key' => 'node.alpha_issue'],
            ['node' => 'charlie', 'key' => 'node.charlie_issue'],
        ]);
});

it('projects the existing fleet node summary fields with complete active roles', function (): void {
    $node = Node::factory()->create(['name' => 'multi-role-1', 'status' => 'active']);
    assert($node instanceof Node, description: 'The factory must produce a node.');
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'database',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    $summary = app(DoctorFleetNodeProjection::class)->nodeSummary($node, [
        'healthy' => true,
        'summary' => ['issues' => 0, 'fixed' => 0],
        'scope' => ['families' => ['node', 'process']],
    ]);

    expect($summary)->toBe([
        'node' => 'multi-role-1',
        'role' => 'app-dev',
        'roles' => ['app-dev', 'database'],
        'healthy' => true,
        'families' => ['node', 'process'],
        'summary' => ['issues' => 0, 'fixed' => 0],
    ]);
});

it('uses safe node summary defaults and an empty operator role set', function (): void {
    $node = Node::factory()->create(['name' => 'operator-1', 'status' => 'active']);
    assert($node instanceof Node, description: 'The factory must produce a node.');

    $summary = app(DoctorFleetNodeProjection::class)->nodeSummary($node, []);

    expect($summary)->toBe([
        'node' => 'operator-1',
        'role' => 'operator',
        'roles' => [],
        'healthy' => false,
        'families' => [],
        'summary' => [],
    ]);
});

it('keeps array issues in their original order and ignores other values', function (): void {
    $issues = app(DoctorFleetNodeProjection::class)->nodeIssues([
        'issues' => [
            'invalid',
            ['key' => 'node.first'],
            null,
            ['key' => 'node.second'],
        ],
    ]);

    expect($issues)->toBe([
        ['key' => 'node.first'],
        ['key' => 'node.second'],
    ]);
});

it('returns empty ordered results for an empty fleet', function (): void {
    $projection = app(DoctorFleetNodeProjection::class);
    /** @var Illuminate\Support\Collection<int, Node> $targets */
    $targets = collect();

    expect($projection->orderedNodeSummaries($targets, [0 => ['node' => 'orphan']]))
        ->toBeEmpty()
        ->and($projection->orderedIssues($targets, [0 => [['key' => 'node.orphan']]]))
        ->toBeEmpty();
});
