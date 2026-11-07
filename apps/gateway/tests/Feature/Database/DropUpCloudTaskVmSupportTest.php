<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('retires cloud state without changing an Incus slot and resumes after partial drops', function (): void {
    Schema::create('upcloud_task_vm_image_builds', static function (Blueprint $table): void {
        $table->id();
    });
    Schema::table('task_vms', static function (Blueprint $table): void {
        $table->string('provider_id')->nullable();
        $table->text('provider_bootstrap')->nullable();
    });
    Schema::table('task_vm_image_recipe', static function (Blueprint $table): void {
        $table->integer('upcloud_rebuild_after_attempt')->nullable();
    });
    $incus = DB::table('task_vms')->insertGetId(['provider' => 'incus', 'name' => 'beast-tvm-1', 'state' => 'ready', 'wireguard_ip' => '10.44.0.131', 'slot_number' => 1]);
    $cloud = DB::table('task_vms')->insertGetId(['provider' => 'upcloud', 'name' => 'tvm-28', 'state' => 'destroyed', 'wireguard_ip' => '10.44.0.132']);
    $migration = require database_path('migrations/2027_01_29_000001_drop_upcloud_task_vm_support.php');
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('upcloud_task_vm_image_builds'))->toBeFalse()
        ->and(Schema::hasColumn('task_vm_image_recipe', 'upcloud_rebuild_after_attempt'))->toBeFalse()
        ->and(Schema::hasColumn('task_vms', 'provider_id'))->toBeFalse()
        ->and(Schema::hasColumn('task_vms', 'provider_bootstrap'))->toBeFalse()
        ->and(Schema::hasColumn('task_vms', 'ssh_host_key'))->toBeFalse()
        ->and(DB::table('task_vms')->where('id', $cloud)->exists())->toBeFalse()
        ->and(DB::table('task_vms')->where('id', $incus)->first())->toMatchObject(['name' => 'beast-tvm-1', 'state' => 'ready', 'slot_number' => 1]);
});

it('refuses to erase a live cloud VM record before any schema changes', function (): void {
    Schema::create('upcloud_task_vm_image_builds', static function (Blueprint $table): void {
        $table->id();
    });
    $cloud = DB::table('task_vms')->insertGetId(['provider' => 'upcloud', 'name' => 'tvm-28', 'state' => 'parked', 'wireguard_ip' => '10.44.0.132']);
    $migration = require database_path('migrations/2027_01_29_000001_drop_upcloud_task_vm_support.php');
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Remove the live UpCloud task VMs')
        ->and(Schema::hasTable('upcloud_task_vm_image_builds'))->toBeTrue()
        ->and(DB::table('task_vms')->where('id', $cloud)->value('state'))->toBe('parked');
});
