<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_connection_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('database_connection_id')->constrained('database_connections')->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained('apps')->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->string('env_prefix');
            $table->timestamps();

            $table->unique(['app_id', 'env_prefix']);
            $table->unique(['workspace_id', 'env_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_connection_targets');
    }
};
