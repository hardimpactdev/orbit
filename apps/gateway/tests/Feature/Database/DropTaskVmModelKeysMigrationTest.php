<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('drops the per-VM model key and its router origin, and runs again after a run that stopped half way', function (): void {
    // The state before the release: both columns exist and a row holds a key.
    Schema::table('task_vms', static function (Blueprint $table): void {
        $table->text('model_key')->nullable();
        $table->string('ai_router_origin')->nullable();
    });
    $host = DB::table('nodes')->insertGetId(['name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit', 'created_at' => now(), 'updated_at' => now()]);
    $vm = DB::table('task_vms')->insertGetId(['host_node_id' => $host, 'provider' => 'incus', 'size' => 'normal', 'name' => 'beast-tvm-1', 'state' => 'ready',
        'wireguard_ip' => '10.44.0.131', 'model_key' => 'old-key', 'ai_router_origin' => 'http://10.44.0.3:8317', 'created_at' => now(), 'updated_at' => now()]);
    $migration = require database_path('migrations/2027_01_28_000000_drop_task_vm_model_keys.php');

    // One column is already gone, as after a run that stopped between the two drops.
    Schema::table('task_vms', static function (Blueprint $table): void {
        $table->dropColumn('ai_router_origin');
    });
    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('task_vms', 'model_key'))->toBeFalse()->and(Schema::hasColumn('task_vms', 'ai_router_origin'))->toBeFalse()
        ->and(DB::table('task_vms')->where('id', $vm)->value('name'))->toBe('beast-tvm-1');
});
