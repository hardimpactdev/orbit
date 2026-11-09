<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A `proof.command` takes its run budget from `input.run_budget_seconds`, which admission now captures. A run that is open at the
 * release was admitted without it; it keeps the fixed 3,600 seconds it was admitted under.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('operations')->where('kind', 'proof.command')->whereNotIn('status', ['succeeded', 'failed', 'cancelled', 'interrupted'])
            ->whereNull('input->run_budget_seconds')->update(['input' => DB::raw("json_set(input, '$.run_budget_seconds', 3600)")]);
    }
};
