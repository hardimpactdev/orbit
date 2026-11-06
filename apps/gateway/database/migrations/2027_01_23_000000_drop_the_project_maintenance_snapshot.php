<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `maintenance-projects` trigger no longer carries a snapshot (docs/cli/project/observe.mmd), so the stored state keeps only its
 * admissions and the last terminal run. This drops the retired snapshot, inputs, revision and flags, about 1.3 MB of JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('scope_type', 'gateway')->where('scope_id', 0)->where('key', 'swamp.project-maintenance')->first();
        if ($row === null || ! is_string($row->value)) {
            return;
        }
        $state = json_decode($row->value, true);
        if (! is_array($state)) {
            return;
        }
        $kept = array_intersect_key($state, ['admissions' => true, 'terminal' => true]);
        if ($kept !== $state) {
            DB::table('settings')->where('id', $row->id)->update(['value' => json_encode($kept, JSON_THROW_ON_ERROR)]);
        }
    }
};
