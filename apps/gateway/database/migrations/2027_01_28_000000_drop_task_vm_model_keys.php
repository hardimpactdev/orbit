<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task VMs hold no per-VM model key any more: every VM holds the one fixed placeholder key of the model router guard, and the guard
 * origin comes from the ai-router state (docs/reference/tasks.md, Credentials in task VMs). The two columns of the old key go. Each drop
 * holds when it ran before, because a run that stopped half way must not break the release on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['model_key', 'ai_router_origin'] as $column) {
            if (Schema::hasColumn('task_vms', $column)) {
                Schema::table('task_vms', static function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
