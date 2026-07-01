<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('node_tools')
            ->where('name', 'opencode-server')
            ->whereExists(static function (Builder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('node_tools as canonical')
                    ->whereColumn('canonical.node_id', 'node_tools.node_id')
                    ->where('canonical.name', 'opencode-cli');
            })
            ->delete();

        DB::table('node_tools')
            ->where('name', 'opencode-server')
            ->update(['name' => 'opencode-cli']);

        DB::table('processes')
            ->whereIn('tool', ['opencode', 'opencode-server'])
            ->update(['tool' => 'opencode-cli']);
    }

    public function down(): void
    {
        DB::table('node_tools')
            ->where('name', 'opencode-cli')
            ->whereExists(static function (Builder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('node_tools as legacy')
                    ->whereColumn('legacy.node_id', 'node_tools.node_id')
                    ->where('legacy.name', 'opencode-server');
            })
            ->delete();

        DB::table('node_tools')
            ->where('name', 'opencode-cli')
            ->update(['name' => 'opencode-server']);

        DB::table('processes')
            ->where('tool', 'opencode-cli')
            ->update(['tool' => 'opencode']);
    }
};
