<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_favorites', function (Blueprint $table) {
            $table->id();
            $table->string('repo_url');
            $table->string('display_name');
            $table->integer('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('db_driver')->nullable();
            $table->string('session_driver')->nullable();
            $table->string('cache_driver')->nullable();
            $table->string('queue_driver')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_favorites');
    }
};
