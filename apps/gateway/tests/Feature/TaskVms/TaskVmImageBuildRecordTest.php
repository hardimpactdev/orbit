<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->host = Node::query()->create(['name' => 'image-host', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']);
});

it('refuses a second running build on a host with the stable error code', function (): void {
    TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id]);

    try {
        TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id]);
        test()->fail('A second running build was accepted.');
    } catch (TaskVmException $exception) {
        expect($exception->errorCode)->toBe('task_vm.image_build_running');
    }

    $this->assertDatabaseCount('task_vm_image_builds', 1);
});

it('enforces the running-host index even when a writer bypasses the model', function (): void {
    TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id]);

    expect(fn () => DB::table('task_vm_image_builds')->insert(['host_node_id' => $this->host->id, 'state' => 'running']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows running builds on two hosts and failed and succeeded builds beside a running build', function (): void {
    $otherHost = Node::query()->create(['name' => 'other-image-host', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']);
    TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'state' => TaskVmImageBuildState::Failed]);
    TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'state' => TaskVmImageBuildState::Succeeded]);
    TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id]);
    TaskVmImageBuild::query()->create(['host_node_id' => $otherHost->id]);

    $this->assertDatabaseCount('task_vm_image_builds', 4);
});

it('refuses resuming a failed build while another build runs on its host', function (): void {
    $failed = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'state' => TaskVmImageBuildState::Failed]);
    TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id]);

    expect(fn () => $failed->update(['state' => TaskVmImageBuildState::Running]))->toThrow(TaskVmException::class);
    expect($failed->fresh()->state)->toBe(TaskVmImageBuildState::Failed);
});

it('casts each state and step and persists the source, results, and recipe program name', function (): void {
    $steps = [
        ['step' => 'launch', 'program' => null, 'started_at' => '2026-11-04T12:00:00Z', 'seconds' => 1.5, 'error' => null],
        ['step' => 'program', 'program' => 'rust-toolchain', 'started_at' => '2026-11-04T12:00:02Z', 'seconds' => 3.5, 'error' => 'compiler unavailable'],
    ];
    $build = TaskVmImageBuild::query()->create([
        'host_node_id' => $this->host->id, 'from_stock' => true, 'source_alias' => 'ubuntu-26.04-vm',
        'source_fingerprint' => 'source-fingerprint', 'image_fingerprint' => 'new-fingerprint',
        'current_step' => TaskVmImageBuildStep::Program, 'current_program' => 'rust-toolchain', 'steps' => $steps,
    ])->fresh();

    expect($build->builder_vm_name)->toBe('tvm-image-'.$build->id)
        ->and($build->hostNode->is($this->host))->toBeTrue()
        ->and($build->from_stock)->toBeTrue()
        ->and($build->source_alias)->toBe('ubuntu-26.04-vm')
        ->and($build->source_fingerprint)->toBe('source-fingerprint')
        ->and($build->image_fingerprint)->toBe('new-fingerprint')
        ->and($build->current_program)->toBe('rust-toolchain')
        ->and($build->steps)->toBe($steps);

    foreach (TaskVmImageBuildState::cases() as $state) {
        $build->update(['state' => $state]);
        expect($build->fresh()->state)->toBe($state);
    }
    foreach (TaskVmImageBuildStep::cases() as $step) {
        $build->update(['current_step' => $step]);
        expect($build->fresh()->current_step)->toBe($step);
    }
});

it('lists newest builds first with every ordered step, seconds and error without writing', function (): void {
    $old = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'state' => 'succeeded', 'current_step' => 'promote', 'source_alias' => 'orbit-task-base', 'source_fingerprint' => 'old-source']);
    $new = TaskVmImageBuild::query()->create([
        'host_node_id' => $this->host->id, 'state' => 'failed', 'current_step' => 'program', 'current_program' => 'rust-toolchain',
        'source_alias' => 'ubuntu-26.04-vm', 'source_fingerprint' => 'stock-source', 'from_stock' => true,
        'steps' => [
            ['step' => 'launch', 'program' => null, 'started_at' => '2026-11-04T12:00:00Z', 'seconds' => 1.5, 'error' => null],
            ['step' => 'program', 'program' => 'rust-toolchain', 'started_at' => '2026-11-04T12:00:02Z', 'seconds' => 3.5, 'error' => 'compiler unavailable'],
            ['step' => 'clean', 'program' => null, 'started_at' => null, 'seconds' => null, 'error' => null],
        ],
    ]);
    $before = DB::table('task_vm_image_builds')->orderBy('id')->get()->toJson();
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('task-vms:image-builds')
        ->expectsOutput("Build {$new->id} | image-host | failed | program:rust-toolchain | source: ubuntu-26.04-vm (stock-source) [stock]")
        ->expectsOutput('  launch | 1.5s | error: -')
        ->expectsOutput('  program:rust-toolchain | 3.5s | error: compiler unavailable')
        ->expectsOutput('  clean | - | error: -')
        ->expectsOutput("Build {$old->id} | image-host | succeeded | promote | source: orbit-task-base (old-source)")
        ->assertSuccessful();

    expect($writes)->toBe([]);
    expect(DB::table('task_vm_image_builds')->orderBy('id')->get()->toJson())->toBe($before);
});

it('reports an empty build history', function (): void {
    $this->artisan('task-vms:image-builds')->expectsOutput('No task VM image builds.')->assertSuccessful();
});

it('runs the additive migration down and up on SQLite', function (): void {
    $migration = require database_path('migrations/2026_11_04_000000_create_task_vm_image_builds_table.php');

    $migration->down();
    expect(Schema::hasTable('task_vm_image_builds'))->toBeFalse();
    $migration->up();
    $attemptMigration = require database_path('migrations/2026_11_04_000002_fence_task_vm_image_build_attempts.php');
    $attemptMigration->up();
    TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id]);

    expect(fn () => DB::table('task_vm_image_builds')->insert(['host_node_id' => $this->host->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});
