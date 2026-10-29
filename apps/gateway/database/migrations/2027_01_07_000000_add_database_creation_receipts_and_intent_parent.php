<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_connections', function (Blueprint $table): void {
            $table->json('creation_receipts')->nullable();
        });
        Schema::table('database_execution_intents', function (Blueprint $table): void {
            $table->foreignId('parent_operation_id')->nullable()->constrained('operations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('database_execution_intents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_operation_id');
        });
        Schema::table('database_connections', function (Blueprint $table): void {
            $table->dropColumn('creation_receipts');
        });
    }
};
