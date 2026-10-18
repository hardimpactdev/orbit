<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')->where('assistance_requested', true)->where('assistance_kind', 'failure')
            ->where(static fn (Builder $reason): Builder => $reason
                ->where('assistance_reason', 'like', 'Workspace removal failed: %')
                ->orWhere('assistance_reason', 'like', 'Merged pull request cleanup failed: %')
                ->orWhere('assistance_reason', 'like', 'Workspace ownership refused: %'))
            ->update(['assistance_kind' => 'removal_failure']);
    }

    public function down(): void
    {
        // Classification is retained: the prior kind cannot distinguish a migrated request from a new one.
    }
};
