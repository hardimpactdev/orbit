<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->string('publish_error_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->dropColumn('publish_error_code');
        });
    }
};
