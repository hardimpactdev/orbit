<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `annotation_events` only allocates annotation revisions now: a row's id is the revision and the submission order.
 * Only the removed annotation event stream read `payload` and `instance_id`, so both columns and their index go.
 * `payload` drops in place. `instance_id` has a table foreign key, so SQLite rebuilds the table to drop it. The
 * rebuild keeps every id but resets the AUTOINCREMENT counter to the highest id, so the counter is restored and no
 * revision repeats. Rolling back restores the schema, not the old payloads or Instance ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNothingElseReadsTheTable();
        $sequence = $this->sequence();
        Schema::table('annotation_events', static function (Blueprint $table): void {
            $table->dropIndex(['instance_id', 'id']);
        });
        Schema::table('annotation_events', static function (Blueprint $table): void {
            $table->dropColumn('payload');
        });
        Schema::table('annotation_events', static function (Blueprint $table): void {
            $table->dropForeign(['instance_id']);
            $table->dropColumn('instance_id');
        });
        $this->restoreSequence($sequence);
    }

    public function down(): void
    {
        $sequence = $this->sequence();
        Schema::table('annotation_events', static function (Blueprint $table): void {
            $table->foreignId('instance_id')->nullable()->after('id')->constrained('instances')->nullOnDelete();
            $table->json('payload')->default('{}')->after('instance_id');
            $table->index(['instance_id', 'id']);
        });
        $this->restoreSequence($sequence);
    }

    /** The rebuild drops the table's triggers and breaks any view or foreign key that names it, so refuse those. */
    private function assertNothingElseReadsTheTable(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $others = DB::table('sqlite_master')->where('sql', 'like', '%annotation_events%')
            ->whereNotIn('name', ['annotation_events', 'annotation_events_instance_id_id_index'])->pluck('name');
        if ($others->isNotEmpty()) {
            throw new RuntimeException('Cannot rebuild annotation_events while these read it: '.$others->implode(', ').'.');
        }
    }

    /** SQLite's AUTOINCREMENT counter, which can be above the highest id. */
    private function sequence(): ?int
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return null;
        }

        $sequence = DB::table('sqlite_sequence')->where('name', 'annotation_events')->value('seq');

        return is_numeric($sequence) ? (int) $sequence : null;
    }

    private function restoreSequence(?int $sequence): void
    {
        if ($sequence === null) {
            return;
        }

        if (DB::table('sqlite_sequence')->where('name', 'annotation_events')->exists()) {
            DB::table('sqlite_sequence')->where('name', 'annotation_events')->where('seq', '<', $sequence)->update(['seq' => $sequence]);
        } else {
            DB::table('sqlite_sequence')->insert(['name' => 'annotation_events', 'seq' => $sequence]);
        }

        if ($this->sequence() !== $sequence) {
            throw new RuntimeException('The annotation revision counter changed during the migration.');
        }
    }
};
