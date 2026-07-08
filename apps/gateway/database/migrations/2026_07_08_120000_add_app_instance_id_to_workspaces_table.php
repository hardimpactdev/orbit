<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table
                ->foreignId('app_instance_id')
                ->nullable()
                ->after('app_id')
                ->constrained('app_instances')
                ->nullOnDelete();

            $table->index(['app_instance_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropIndex(['app_instance_id', 'name']);
            $table->dropConstrainedForeignId('app_instance_id');
        });
    }
};
