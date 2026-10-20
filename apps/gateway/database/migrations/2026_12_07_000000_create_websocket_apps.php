<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the one Reverb app the `websocket` role stored as Node settings into the `gateway` row of
 * `websocket_apps`, and deletes those settings (ADR 0227). The Laravel `APP_KEY` of the Reverb server
 * stays a Node setting, because it belongs to the server, not to an app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websocket_apps', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 63)->unique();
            $table->string('app_id', 64)->unique();
            $table->string('key', 64)->unique();
            $table->text('secret');
            $table->timestamps();
        });

        Schema::create('websocket_app_targets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('websocket_app_id')->constrained()->restrictOnDelete();
            $table->foreignId('instance_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        $keys = ['websocket.reverb.app_id', 'websocket.reverb.app_key', 'websocket.reverb.app_secret'];
        $nodeId = DB::table('node_roles')->where('role', 'websocket')->value('node_id');
        $values = $nodeId === null ? collect() : DB::table('settings')
            ->where('scope_type', 'node')
            ->where('scope_id', $nodeId)
            ->whereIn('key', $keys)
            ->pluck('value', 'key');

        if ($values->count() === count($keys)) {
            $now = now();
            DB::table('websocket_apps')->insert([
                'name' => 'gateway',
                'app_id' => $values['websocket.reverb.app_id'],
                'key' => $values['websocket.reverb.app_key'],
                // The setting is encrypted the way the `encrypted` cast encrypts, so it moves as it is.
                'secret' => $values['websocket.reverb.app_secret'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('settings')->where('scope_type', 'node')->whereIn('key', $keys)->delete();
    }
};
