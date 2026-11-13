<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

dataset('dropped registration columns', [[[
    'registration_primary', 'registration_include_worktrees', 'registration_repository_url', 'registration_repository_identity',
    'registration_default_branch', 'registration_inferred_slug', 'registration_inferred_root', 'registration_worktree_paths',
    'registration_relocation_state', 'registration_authoritative_path', 'registration_route_provenance', 'registration_source_device',
    'registration_source_inode',
], ['fleet_rollout_nodes', 'fleet_rollouts', 'task_github_review_consumptions', 'task_github_review_feedback']]]);

it('drops the unread registration columns and the four orphan tables, and keeps the columns still in use', function (array $columns, array $tables): void {
    expect(array_filter($columns, static fn (string $column): bool => Schema::hasColumn('instances', $column)))->toBe([])
        ->and(array_filter($tables, static fn (string $table): bool => Schema::hasTable($table)))->toBe([])
        ->and(Schema::hasColumns('instances', ['registration_original_path', 'registration_request_id', 'registration_route_domain',
            'registration_source_digest', 'registration_detached', 'registration_common_repository_path', 'registration_completed_at']))->toBeTrue();
})->with('dropped registration columns');

it('restores every structure empty on down, and runs up again after a run that stopped half way', function (array $columns, array $tables): void {
    $migration = require database_path('migrations/2027_02_11_000000_drop_unread_registration_columns_and_tables.php');

    $migration->down();

    expect(Schema::hasColumns('instances', $columns))->toBeTrue();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    // A run that stopped after the column drop: the tables are still there.
    Schema::table('instances', static fn ($table) => $table->dropColumn($columns));
    $migration->up();
    $migration->up();

    expect(array_filter($columns, static fn (string $column): bool => Schema::hasColumn('instances', $column)))->toBe([])
        ->and(array_filter($tables, static fn (string $table): bool => Schema::hasTable($table)))->toBe([]);
})->with('dropped registration columns');
