<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->string('commit_sha', 64)->nullable();
            $table->unsignedInteger('pull_request_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->dropColumn(['commit_sha', 'pull_request_number']);
        });
    }
};
