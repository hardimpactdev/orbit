<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The review receipt that a reviewer turn posts to its check (docs/reference/tasks.md#the-review-receipt), beside its pull request text.
 * Expand only: both columns are nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->json('review_receipt')->nullable();
            $table->foreignId('review_receipt_operation_id')->nullable()->constrained('operations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('review_receipt_operation_id');
            $table->dropColumn('review_receipt');
        });
    }
};
