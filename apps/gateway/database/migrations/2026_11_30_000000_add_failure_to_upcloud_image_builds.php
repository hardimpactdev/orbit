<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upcloud_task_vm_image_builds', function (Blueprint $table): void {
            $table->json('failure')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('upcloud_task_vm_image_builds', function (Blueprint $table): void {
            $table->dropColumn('failure');
        });
    }
};
