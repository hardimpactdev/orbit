<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_vm_image_builds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_node_id')->constrained('nodes')->restrictOnDelete();
            $table->string('source_alias')->nullable();
            $table->string('source_fingerprint')->nullable();
            $table->boolean('from_stock')->default(false);
            $table->string('builder_vm_name')->nullable();
            $table->string('image_fingerprint')->nullable();
            $table->string('current_step')->nullable();
            $table->string('current_program')->nullable();
            $table->string('state')->default('running');
            $table->json('steps')->default('[]');
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX task_vm_image_builds_running_host_unique ON task_vm_image_builds (host_node_id) WHERE state = 'running'");
    }

    public function down(): void
    {
        Schema::dropIfExists('task_vm_image_builds');
    }
};
