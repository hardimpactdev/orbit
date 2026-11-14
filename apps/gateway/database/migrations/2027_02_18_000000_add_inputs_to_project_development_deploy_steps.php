<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The repository paths that let a container deployment skip an unchanged step (docs/reference/deployments.md#step-inputs). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_development_deploy_steps', function (Blueprint $table): void {
            $table->json('inputs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_development_deploy_steps', function (Blueprint $table): void {
            $table->dropColumn('inputs');
        });
    }
};
