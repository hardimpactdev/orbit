<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vm_image_builds', function (Blueprint $table): void {
            $table->dropUnique(['host_node_id', 'recipe_identity']);
            $table->unique(['host_node_id', 'recipe_identity', 'recipe_generation'], 'task_vm_image_builds_recipe_generation_unique');
        });
        Schema::table('task_vm_image_recipe', function (Blueprint $table): void {
            $table->unsignedBigInteger('upcloud_rebuild_after_attempt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_vm_image_recipe', function (Blueprint $table): void {
            $table->dropColumn('upcloud_rebuild_after_attempt');
        });
        Schema::table('task_vm_image_builds', function (Blueprint $table): void {
            $table->dropUnique('task_vm_image_builds_recipe_generation_unique');
            $table->unique(['host_node_id', 'recipe_identity']);
        });
    }
};
