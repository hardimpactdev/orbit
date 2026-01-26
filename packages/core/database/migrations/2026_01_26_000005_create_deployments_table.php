<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('local_path')->nullable();
            $table->string('url')->nullable();
            $table->string('orchestrator_id')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'environment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
