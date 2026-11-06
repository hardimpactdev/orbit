<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ready slot is a task VM enrolled ahead of time for one Project and kept stopped (docs/cli/tasks/vm-slots.mmd). The
 * row names that Project, and the digest of what a task image carries in the release whose converge it got.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->foreignId('slot_project_id')->nullable()->after('group_id')->constrained('projects')->nullOnDelete();
            $table->string('slot_digest', 64)->nullable()->after('slot_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('slot_project_id');
            $table->dropColumn('slot_digest');
        });
    }
};
