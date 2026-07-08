<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_instance_runtime_mounts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
            $table->string('source', 512);
            $table->string('target', 512);
            $table->boolean('read_only')->default(true);
            $table->timestamps();

            $table->unique(['app_instance_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_instance_runtime_mounts');
    }
};
