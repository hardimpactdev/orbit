<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_environment_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('entries');
            $table->string('source_path', 512);
            $table->string('source_commit', 64);
            $table->string('source_blob', 64)->nullable();
            $table->string('checked_head', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_environment_templates');
    }
};
