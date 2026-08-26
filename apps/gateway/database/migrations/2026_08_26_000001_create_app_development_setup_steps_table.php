<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_development_setup_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->text('command');
            $table->unsignedInteger('timeout_seconds')->default(600);
            $table->timestamps();
            $table->unique(['app_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_setup_steps');
    }
};
