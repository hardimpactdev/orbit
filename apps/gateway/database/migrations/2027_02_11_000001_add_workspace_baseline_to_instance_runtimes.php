<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The workspace state recorded when a container create or clone ends (docs/reference/development-containers.md#workspace-work-state). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->json('workspace_baseline')->nullable();
            $table->timestamp('workspace_baseline_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->dropColumn(['workspace_baseline', 'workspace_baseline_at']);
        });
    }
};
