<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the process name on each durable lifecycle event so SSE/list
 * consumers can render name without depending on a live process FK.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('process_events', static function (Blueprint $table): void {
            $table->string('process_name')->nullable()->after('process_id');
        });

        // Backfill from processes when the relation still exists.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(
                'UPDATE process_events
                 SET process_name = (
                     SELECT processes.name FROM processes WHERE processes.id = process_events.process_id
                 )
                 WHERE process_name IS NULL AND process_id IS NOT NULL',
            );
        } else {
            DB::table('process_events')
                ->whereNull('process_name')
                ->whereNotNull('process_id')
                ->orderBy('id')
                ->chunkById(500, static function ($rows): void {
                    foreach ($rows as $row) {
                        $name = DB::table('processes')->where('id', $row->process_id)->value('name');

                        if (is_string($name) && $name !== '') {
                            DB::table('process_events')
                                ->where('id', $row->id)
                                ->update(['process_name' => $name]);
                        }
                    }
                });
        }

        DB::table('process_events')
            ->whereNull('process_name')
            ->orWhere('process_name', '')
            ->update(['process_name' => 'unknown']);
    }

    public function down(): void
    {
        Schema::table('process_events', static function (Blueprint $table): void {
            $table->dropColumn('process_name');
        });
    }
};
