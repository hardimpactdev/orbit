<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->foreignId('parent_operation_id')->nullable()->constrained('operations')->restrictOnDelete();
            $table->string('child_key', 128)->nullable();
            $table->json('child_admission')->nullable();
            $table->string('child_fingerprint', 64)->nullable();
            $table->json('run_constraints')->nullable();
            $table->unique(['parent_operation_id', 'child_key']);
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropForeign(['parent_operation_id']);
            $table->dropUnique(['parent_operation_id', 'child_key']);
            $table->dropColumn(['parent_operation_id', 'child_key', 'child_admission', 'child_fingerprint', 'run_constraints']);
        });
    }
};
