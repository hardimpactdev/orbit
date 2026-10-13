<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The declared topology that a task VM prepares nested in itself: the union it serves, whether its preparation unit
 * runs or finished, that unit's run token and start, and the reason of the last failed preparation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->json('topology')->nullable()->after('size');
            $table->string('topology_state')->nullable()->after('topology');
            $table->string('topology_run')->nullable()->after('topology_state');
            $table->timestamp('topology_started_at')->nullable()->after('topology_run');
            $table->text('topology_error')->nullable()->after('topology_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->dropColumn(['topology', 'topology_state', 'topology_run', 'topology_started_at', 'topology_error']);
        });
    }
};
