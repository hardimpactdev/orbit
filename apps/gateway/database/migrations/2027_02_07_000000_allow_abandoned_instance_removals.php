<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            DB::transaction(function (): void {
                // SQLite rebuilds a changed enum. Retain every trigger, including cross-table references,
                // and disable cascades before the transaction so the history survives the rebuild.
                $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger'");
                foreach ($triggers as $trigger) {
                    DB::unprepared('DROP TRIGGER "'.str_replace('"', '""', $trigger->name).'"');
                }
                Schema::table('instance_removals', function (Blueprint $table): void {
                    $table->enum('status', ['removing', 'failed', 'completed', 'abandoned'])->change();
                });
                foreach ($triggers as $trigger) {
                    DB::unprepared($trigger->sql);
                }
                $this->contracts();
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new LogicException('Abandoned removal migration changed foreign-key integrity.');
                }
            });
        });
    }

    private function contracts(): void
    {
        Schema::table('instance_removal_members', function (Blueprint $table): void {
            $table->timestamp('abandoned_at')->nullable();
        });
        DB::unprepared('DROP INDEX instance_removal_members_live_unique;
            CREATE UNIQUE INDEX instance_removal_members_live_unique ON instance_removal_members(instance_id)
            WHERE row_deleted_at IS NULL AND abandoned_at IS NULL;');

        $this->amend('instance_removals_immutable', [
            "NEW.status NOT IN ('removing', 'failed', 'completed')" => "NEW.status NOT IN ('removing', 'failed', 'completed', 'abandoned')",
            "OR (OLD.status = 'completed' AND NEW.status <> 'completed')" => <<<'SQL'
                OR (OLD.status = 'completed' AND NEW.status <> 'completed')
                OR OLD.status = 'abandoned'
                OR (NEW.status = 'abandoned' AND (
                    OLD.status <> 'failed'
                    OR NEW.current_step IS NOT NULL OR NEW.failed_step IS NOT NULL OR NEW.error_code IS NOT NULL
                    OR (SELECT COUNT(*) FROM instance_removal_members WHERE instance_removal_id = NEW.id) <> NEW.total
                    OR EXISTS (SELECT 1 FROM instance_removal_members WHERE instance_removal_id = NEW.id
                        AND (abandoned_at IS NULL OR row_deleted_at IS NOT NULL))
                ))
                SQL,
        ]);
        $this->amend('instance_removal_members_insert', [
            'OR NEW.row_deleted_at IS NOT NULL' => 'OR NEW.row_deleted_at IS NOT NULL OR NEW.abandoned_at IS NOT NULL',
        ]);
        $this->amend('instance_removal_members_immutable', [
            'WHEN NEW.instance_removal_id' => <<<'SQL'
                WHEN OLD.abandoned_at IS NOT NULL
                    OR (NEW.abandoned_at IS NOT NULL AND (
                        NEW.row_deleted_at IS NOT NULL
                        OR NEW.source_finalized_at IS NOT NULL
                        OR NEW.runtime_cleaned_at IS NOT NULL
                        OR (SELECT status FROM instance_removals WHERE id = NEW.instance_removal_id) IS NOT 'failed'
                        OR (SELECT status FROM instances WHERE id = NEW.instance_id) IS NOT 'removing'
                    ))
                    OR NEW.instance_removal_id
                SQL,
        ]);
        $this->amend('instances_removal_status_update', [
            "(OLD.status = 'removing' AND NEW.status <> 'removing')" => <<<'SQL'
                (OLD.status = 'removing' AND NEW.status <> 'removing' AND (
                    NEW.status <> 'active'
                    OR NOT EXISTS (
                        SELECT 1 FROM instance_removal_members AS member
                        JOIN instance_removals AS removal ON removal.id = member.instance_removal_id
                        WHERE member.instance_id = NEW.id AND member.abandoned_at IS NOT NULL
                            AND member.row_deleted_at IS NULL AND removal.status = 'abandoned'
                    )
                    OR EXISTS (SELECT 1 FROM instance_removal_members WHERE instance_id = NEW.id
                        AND row_deleted_at IS NULL AND abandoned_at IS NULL)
                ))
                SQL,
            'instance_removal_members.row_deleted_at IS NULL' => 'instance_removal_members.row_deleted_at IS NULL AND instance_removal_members.abandoned_at IS NULL',
        ]);
        foreach (['route_targets_active_delete', 'routes_active_target_delete'] as $trigger) {
            $this->amend($trigger, [
                'instance_removal_members.row_deleted_at IS NULL' => 'instance_removal_members.row_deleted_at IS NULL AND instance_removal_members.abandoned_at IS NULL',
            ]);
        }
    }

    /** Preserve all existing contract clauses while changing their terminal/live-state rules.
     * @param  array<string,string>  $changes
     */
    private function amend(string $name, array $changes): void
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        $sql = $row->sql ?? null;
        if (! is_string($sql)) {
            throw new LogicException("Missing removal contract: {$name}.");
        }
        foreach ($changes as $before => $after) {
            if (! str_contains($sql, $before)) {
                throw new LogicException("Removal contract {$name} changed before migration.");
            }
            $sql = str_replace($before, $after, $sql);
        }
        DB::unprepared("DROP TRIGGER {$name}");
        DB::unprepared($sql);
    }
};
