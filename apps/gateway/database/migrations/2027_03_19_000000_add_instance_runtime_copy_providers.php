<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The providers whose instances a task copy's T3 settings got at its create (docs/reference/tasks.md#copy-turn-agents). A new copy
 * records them at its reservation. An existing copy records Claude and Codex: whether its settings got the Antigravity instance is
 * not recorded anywhere, so it runs no Antigravity thread. Every other runtime records none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table) {
            $table->json('copy_providers')->nullable();
        });
        DB::table('instance_runtimes')
            ->whereIn('instance_id', DB::table('instances')->whereNotNull('task_workspace_owner_id')->select('id'))
            ->update(['copy_providers' => json_encode(['claude', 'codex'])]);
    }

    public function down(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table) {
            $table->dropColumn('copy_providers');
        });
    }
};
