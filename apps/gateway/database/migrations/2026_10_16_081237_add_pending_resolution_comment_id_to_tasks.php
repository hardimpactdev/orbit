<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->text('pending_resolution_reason')->nullable();
            $table->text('pending_resolution_message')->nullable();
            $table->foreignId('pending_resolution_comment_id')->nullable()->constrained('task_comments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pending_resolution_comment_id');
            $table->dropColumn(['pending_resolution_reason', 'pending_resolution_message']);
        });
    }
};
