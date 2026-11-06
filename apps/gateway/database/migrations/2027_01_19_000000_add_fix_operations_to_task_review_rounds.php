<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_review_rounds', static function (Blueprint $table): void {
            $table->foreignId('fix_turn_operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->foreignId('fix_check_operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->foreignId('fix_publish_operation_id')->nullable()->constrained('operations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_review_rounds', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fix_turn_operation_id');
            $table->dropConstrainedForeignId('fix_check_operation_id');
            $table->dropConstrainedForeignId('fix_publish_operation_id');
        });
    }
};
