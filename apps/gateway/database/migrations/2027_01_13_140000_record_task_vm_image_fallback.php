<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->unsignedBigInteger('image_build_id')->nullable();
            $table->boolean('image_fallback')->default(false);
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->boolean('current_recipe')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->dropColumn(['image_build_id', 'image_fallback']);
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('current_recipe');
        });
    }
};
