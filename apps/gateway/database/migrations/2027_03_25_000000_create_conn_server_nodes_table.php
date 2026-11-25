<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which server each Conn Server Node runs, for the Conn Server rollout (docs/reference/development-containers.md#conn-server-rollout):
 * its platform, the bundle path or `current` link, the LaunchAgent label or systemd unit, the port, the user that runs it, the canary
 * and whether its users hear before a restart. Seeds the four Nodes that run one today, where they exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_server_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('platform', 8);
            $table->string('path', 255);
            $table->string('service', 255);
            $table->unsignedSmallInteger('port');
            $table->string('user', 64);
            $table->boolean('canary')->default(false);
            $table->boolean('notify_before_restart')->default(false);
            $table->timestamps();
        });

        $app = '~/Applications/Conn Server.app';
        $seeds = [
            8 => ['platform' => 'mac', 'path' => $app, 'service' => 'com.nckrtl.t3-headless', 'port' => 3780],
            11 => ['platform' => 'mac', 'path' => $app, 'service' => 'com.t3tools.t3code.service', 'port' => 3773, 'notify_before_restart' => true],
            45 => ['platform' => 'mac', 'path' => $app, 'service' => 'com.nckrtl.conn-server', 'port' => 3773, 'canary' => true],
            1842 => ['platform' => 'linux', 'path' => '/opt/conn-server/current', 'service' => 'conn-server', 'port' => 3773, 'user' => 'conn'],
        ];
        foreach (DB::table('nodes')->whereIn('id', array_keys($seeds))->get(['id', 'user']) as $node) {
            DB::table('conn_server_nodes')->insert(['canary' => false, 'notify_before_restart' => false, 'user' => $node->user,
                ...$seeds[$node->id], 'node_id' => $node->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_server_nodes');
    }
};
