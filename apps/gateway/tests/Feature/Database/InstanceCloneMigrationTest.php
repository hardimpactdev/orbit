<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Carbon\CarbonImmutable;

it('persists clone evidence with integer and immutable time casts', function (): void {
    [$project, $candidateNode] = clone_migration_parents('casts-candidate');
    [, $targetNode] = clone_migration_parents('casts-target', $project);
    $candidate = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $candidateNode->id,
        'name' => 'candidate',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/clone-migration/candidate',
    ]);
    $completedAt = CarbonImmutable::parse('2026-09-12T12:34:56+00:00');

    $target = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $targetNode->id,
        'name' => 'target',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-clone-target',
        'clone_candidate_id' => (string) $candidate->id,
        'clone_candidate_commit' => str_repeat('a', 40),
        'clone_requested_branch' => 'release/one',
        'clone_preview_name' => 'preview',
        'clone_preview_domain' => 'preview.prod.orbit',
        'clone_sqlite_source_path' => 'database/source.sqlite',
        'clone_completed_at' => $completedAt,
    ])->refresh();

    expect($target->clone_candidate_id)
        ->toBe($candidate->id)
        ->and($target->clone_candidate_commit)
        ->toBe(str_repeat('a', 40))
        ->and($target->clone_requested_branch)
        ->toBe('release/one')
        ->and($target->clone_preview_name)
        ->toBe('preview')
        ->and($target->clone_preview_domain)
        ->toBe('preview.prod.orbit')
        ->and($target->clone_sqlite_source_path)
        ->toBe('database/source.sqlite')
        ->and($target->clone_completed_at)
        ->toBeInstanceOf(CarbonImmutable::class)
        ->and($target->clone_completed_at?->toIso8601String())
        ->toBe('2026-09-12T12:34:56+00:00');
});

/** @return array{Project, Node} */
function clone_migration_parents(string $suffix, ?Project $project = null): array
{
    $count = Node::query()->count();
    $node = Node::query()->create([
        'name' => "clone-migration-{$suffix}-{$count}",
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(140 + $count),
    ]);
    $project ??= Project::query()->create([
        'name' => "Clone migration {$suffix}",
        'slug' => "clone-migration-{$suffix}",
        'repository_url' => "https://example.test/clone-migration-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return [$project, $node];
}
