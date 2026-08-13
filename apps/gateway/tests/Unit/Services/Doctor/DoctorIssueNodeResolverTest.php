<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorIssueNodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves the node named by a Doctor issue with a fresh query', function (): void {
    $node = Node::factory()->create(['name' => 'issue-node']);
    $issue = app(DoctorIssueFactory::class)->fromArray([
        'family' => 'node',
        'node' => 'issue-node',
        'key' => 'node.managed_agent_intent_invalid',
        'kind' => 'divergent',
        'summary' => 'Managed Agent intent is invalid.',
    ]);

    $resolved = app(DoctorIssueNodeResolver::class)->resolve($issue);

    expect($resolved)
        ->toBeInstanceOf(Node::class)
        ->not
        ->toBe($node)
        ->and($resolved?->is($node))
        ->toBeTrue();
});

it('returns null when a Doctor issue has no known node', function (?string $nodeName): void {
    $issue = app(DoctorIssueFactory::class)->fromArray([
        'family' => 'node',
        'node' => $nodeName,
        'key' => 'node.managed_agent_intent_invalid',
        'kind' => 'divergent',
        'summary' => 'Managed Agent intent is invalid.',
    ]);

    expect(app(DoctorIssueNodeResolver::class)->resolve($issue))->toBeNull();
})->with([
    'missing node name' => null,
    'unknown node name' => 'unknown-node',
]);
