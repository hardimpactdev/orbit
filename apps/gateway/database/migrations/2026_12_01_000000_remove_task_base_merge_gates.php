<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_checks')->where('kind', 'base_merge')->delete();
        DB::table('task_reviewed_commits')->where('source', 'base_merge')->update(['source' => 'orbit_push']);
    }
};
