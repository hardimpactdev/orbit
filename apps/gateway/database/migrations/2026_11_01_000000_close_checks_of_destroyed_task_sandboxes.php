<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A check that ran in an old task sandbox can never be read again: every sandbox is destroyed, and no code reads
 * them any more. Such a check that still shows `running` is lost. On the live Gateway these are checks 1516, 1517,
 * and 1521, of cancelled groups. Rolling back leaves them lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_checks')
            ->where('status', 'running')
            ->whereNotNull('task_sandbox_id')
            ->update([
                'status' => 'lost',
                'output' => 'The task sandbox that ran this check was destroyed before Orbit read its result.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void {}
};
