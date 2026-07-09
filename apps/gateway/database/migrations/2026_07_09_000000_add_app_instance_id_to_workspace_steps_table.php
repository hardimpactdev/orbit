<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspace_steps', function (Blueprint $table): void {
            $table
                ->foreignId('app_instance_id')
                ->nullable()
                ->after('app_id')
                ->constrained('app_instances')
                ->cascadeOnDelete();

            $table->index(['app_instance_id', 'phase', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_steps', function (Blueprint $table): void {
            $table->dropForeign(['app_instance_id']);
            $table->dropIndex(['app_instance_id', 'phase', 'sort_order']);
            $table->dropColumn('app_instance_id');
        });
    }
};
