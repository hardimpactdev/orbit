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
            $table->unsignedInteger('attempt')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('task_vm_image_builds', function (Blueprint $table): void {
            $table->dropColumn('attempt');
        });
    }
};
