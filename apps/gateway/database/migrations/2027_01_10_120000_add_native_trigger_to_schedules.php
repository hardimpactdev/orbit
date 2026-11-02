<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            // Existing Schedules keep their Node timer until the one-off move publishes their own Swamp workflow.
            $table->string('trigger')->default('node');
            $table->uuid('swamp_workflow_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropUnique(['swamp_workflow_id']);
            $table->dropColumn(['trigger', 'swamp_workflow_id']);
        });
    }
};
