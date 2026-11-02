<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('php_version')->nullable();
            $table->boolean('is_laravel')->nullable();
            $table->string('source_profile_error')->nullable();
            $table->dateTime('source_facts_captured_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['php_version', 'is_laravel', 'source_profile_error', 'source_facts_captured_at']);
        });
    }
};
