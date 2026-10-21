<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websocket_apps', static function (Blueprint $table): void {
            $table->unsignedBigInteger('credential_generation')->default(1);
        });
        Schema::create('websocket_registry', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->uuid('epoch');
            $table->unsignedBigInteger('revision');
        });
        DB::table('websocket_registry')->insert(['id' => 1, 'epoch' => (string) Str::uuid(), 'revision' => 1]);
        Schema::create('websocket_runtimes', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->string('slot')->default('default');
            $table->unique(['node_id', 'slot']);
            $table->text('registry_token');
            $table->text('notification_token');
            $table->boolean('required')->default(true);
            $table->uuid('boot_id')->nullable();
            $table->uuid('epoch')->nullable();
            $table->unsignedBigInteger('applied_revision')->nullable();
            $table->unsignedBigInteger('report_sequence')->default(0);
            $table->timestamp('reported_at')->nullable();
            $table->string('state')->default('starting');
            $table->json('measurements')->nullable();
            $table->timestamps();
        });
        Schema::create('websocket_runtime_boots', static function (Blueprint $table): void {
            $table->uuid('runtime_id');
            $table->uuid('boot_id');
            $table->primary(['runtime_id', 'boot_id']);
            $table->foreign('runtime_id')->references('id')->on('websocket_runtimes')->cascadeOnDelete();
        });
        Schema::create('websocket_registry_operations', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->string('idempotency_key', 128);
            $table->unique(['actor_node_id', 'idempotency_key']);
            $table->string('kind');
            $table->string('app_name')->nullable();
            $table->unsignedBigInteger('credential_generation')->nullable();
            $table->uuid('epoch');
            $table->unsignedBigInteger('revision');
            $table->json('result');
            $table->timestamps();
        });
    }
};
