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
            // UTC ISO strings retain microseconds, identifying the submission acknowledged by its dispatcher.
            $table->string('submission_pending_at')->nullable();
            $table->timestamp('cleanup_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_vm_image_builds', function (Blueprint $table): void {
            $table->dropColumn(['submission_pending_at', 'cleanup_started_at']);
        });
    }
};
