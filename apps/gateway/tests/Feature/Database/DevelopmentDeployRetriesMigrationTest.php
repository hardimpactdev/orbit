<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\DevelopmentDeployRetries;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

it('imports retry JSON once and preserves stopped SHAs and resets across down and repeated up', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'retry-migration', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit']));
    $project = Project::query()->create(['name' => 'retry', 'slug' => 'retry', 'repository_url' => 'https://github.com/nckrtl/retry.git', 'default_branch' => 'main', 'root' => '.']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/retry/default']);
    $sha = str_repeat('b', 40);
    $ledger = ['manual_after' => 7, 'observed_through' => 9, 'unsettled' => [8], 'commits' => [[
        'commit' => $sha, 'attempts' => 3, 'last_failure_at' => '2026-11-01T00:00:00+00:00', 'stopped' => true,
        'evidence' => [['key' => 'one', 'deployment_id' => null], ['key' => 'two', 'deployment_id' => 8], ['key' => 'three', 'deployment_id' => 9]],
        'reset_operation_id' => 4, 'reset_after' => 7, 'reset_at' => null,
    ]]];
    $instance->update(['auto_deploy_state' => ['result' => 'commit_failed', 'development_retries' => $ledger]]);
    Schema::drop('development_deploy_retries');
    $migration = require database_path('migrations/2027_02_04_000002_create_development_deploy_retries.php');

    $migration->up();
    $migration->up();
    $migration->down();
    expect(app(DevelopmentDeployRetries::class)->read($instance->fresh(), [$sha]))->toBe($ledger);
    expect($instance->fresh()->auto_deploy_state)->not->toHaveKey('development_retries')
        ->and($instance->fresh()->auto_deploy_state['result'])->toBe('commit_failed');
    // Reset, then replay a stale JSON key as might be left by rolled-back code.
    app(DevelopmentDeployRetries::class)->manual($instance->refresh(), 10);
    $instance->save();
    $instance->update(['auto_deploy_state' => [...$instance->auto_deploy_state, 'development_retries' => $ledger]]);
    $migration->up();
    expect(app(DevelopmentDeployRetries::class)->read($instance->fresh(), [$sha]))
        ->toBe(['manual_after' => 10, 'observed_through' => 9, 'unsettled' => [], 'commits' => []]);
    expect(DB::table('development_deploy_retries')->where('instance_id', $instance->id)->count())->toBe(0);
});
