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
        Schema::table('task_vm_image_builds', function (Blueprint $table): void {
            $table->string('recipe_identity')->nullable();
            $table->text('recipe_json')->nullable();
            $table->unsignedBigInteger('recipe_generation')->nullable();
            $table->json('images')->default('{}');
            $table->timestamp('promoted_at')->nullable();
            $table->unique(['host_node_id', 'recipe_identity']);
        });
        Schema::create('task_vm_image_recipe', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('identity')->nullable();
            $table->text('recipe_json')->nullable();
            $table->unsignedBigInteger('generation')->default(0);
        });
        DB::table('task_vm_image_recipe')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('task_vm_image_recipe');
        Schema::table('task_vm_image_builds', function (Blueprint $table): void {
            $table->dropUnique(['host_node_id', 'recipe_identity']);
            $table->dropColumn(['recipe_identity', 'recipe_json', 'recipe_generation', 'images', 'promoted_at']);
        });
    }
};
