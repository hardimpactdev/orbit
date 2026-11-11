<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_node_service_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->foreignId('process_id')->constrained()->restrictOnDelete();
            $table->string('protocol');
            $table->unsignedInteger('port');
            $table->string('host_env_key');
            $table->string('port_env_key');
            $table->timestamps();
            $table->unique(['instance_id', 'host_env_key']);
            $table->unique(['instance_id', 'port_env_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_node_service_targets');
    }
};
