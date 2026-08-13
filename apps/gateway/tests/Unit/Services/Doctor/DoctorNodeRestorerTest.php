<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorNodeRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('repairs the issue-named node and returns the completed Doctor action', function (): void {
    $fallback = Node::factory()->create(['name' => 'fallback-node', 'managed' => true]);
    $target = Node::factory()->create(['name' => 'issue-node', 'managed' => true]);
    $issue = app(DoctorIssueFactory::class)->fromArray([
        'family' => 'node',
        'node' => 'issue-node',
        'key' => 'node.managed_agent_intent_invalid',
        'code' => 'node.managed_agent_intent_invalid',
        'kind' => 'divergent',
        'summary' => 'Managed Agent intent is invalid.',
        'detail' => ['managed' => true],
    ]);

    $action = app(DoctorNodeRestorer::class)->apply($fallback, $issue);

    expect($action)
        ->toBe([
            'family' => 'node',
            'node' => 'issue-node',
            'code' => 'node.managed_agent_intent_invalid',
            'key' => 'node.managed_agent_intent_invalid',
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => 'Managed Agent intent is invalid.',
            'details' => ['managed' => true],
        ])
        ->and($target->fresh()?->managed)
        ->toBeFalse()
        ->and($fallback->fresh()?->managed)
        ->toBeTrue();
});

it('returns a failed Doctor action when node repair throws', function (): void {
    $node = Node::factory()->create(['name' => 'fallback-node']);
    $issue = app(DoctorIssueFactory::class)->fromArray([
        'family' => 'node',
        'node' => 'fallback-node',
        'key' => 'node.updates',
        'code' => 'node.updates_reboot_required',
        'kind' => 'divergent',
        'summary' => 'Node requires a reboot.',
        'detail' => ['code' => 'node.updates_reboot_required'],
    ]);

    expect(app(DoctorNodeRestorer::class)->apply($node, $issue))->toBe([
        'family' => 'node',
        'node' => 'fallback-node',
        'code' => 'node.updates_reboot_required',
        'key' => 'node.updates',
        'mode' => 'restore',
        'status' => 'failed',
        'summary' => 'Failed to fix node.updates_reboot_required.',
        'details' => [
            'error' => 'Node update reboot-required drift is not restorable.',
        ],
    ]);
});
