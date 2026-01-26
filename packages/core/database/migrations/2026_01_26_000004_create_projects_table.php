<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('slug')->unique();
            $table->string('path');
            $table->string('php_version')->nullable();
            $table->string('github_repo')->nullable();
            $table->string('project_type')->nullable();
            $table->boolean('has_public_folder')->default(false);
            $table->string('domain')->nullable();
            $table->string('url')->nullable();
            $table->string('status')->default('active');
            $table->text('error_message')->nullable();
            $table->uuid('job_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
