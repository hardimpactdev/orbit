<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ready slot is a task VM enrolled ahead of time for one Project and kept stopped (docs/cli/tasks/vm-slots.mmd). The
 * row names that Project, and the digest of what a task image carries in the release whose converge it got. Plain
 * columns: a foreign key makes SQLite rebuild `task_vms`. The guards finish a run that stopped after the first column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            if (! Schema::hasColumn('task_vms', 'slot_project_id')) {
                $table->unsignedBigInteger('slot_project_id')->nullable();
            }
            if (! Schema::hasColumn('task_vms', 'slot_digest')) {
                $table->string('slot_digest', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->dropColumn(['slot_project_id', 'slot_digest']);
        });
    }
};
