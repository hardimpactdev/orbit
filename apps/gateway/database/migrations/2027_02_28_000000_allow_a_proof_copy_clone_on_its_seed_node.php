<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A clone is made on its default's Node, or on another Node whose `proof_seeds` row holds the default's current seed: the same commit
 * and the same time taken (docs/reference/development-containers.md#create, ADR 0260 build step 5). Only a proof copy is placed there.
 * The trigger checks inserts only, so a rollback keeps the copies that exist and refuses new ones on another Node, as the previous
 * release does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replace(<<<'SQL'
            AND (source.node_id = clone.node_id OR EXISTS (
                SELECT 1 FROM proof_seeds seed
                WHERE seed.node_id = clone.node_id
                    AND seed."commit" = source_runtime.seed_commit
                    AND seed.taken_at = source_runtime.seed_taken_at
            ))
            SQL);
    }

    public function down(): void
    {
        $this->replace('AND source.node_id = clone.node_id');
    }

    private function replace(string $sameNode): void
    {
        DB::statement('DROP TRIGGER instance_runtimes_insert');
        DB::unprepared(<<<SQL
            CREATE TRIGGER instance_runtimes_insert
            BEFORE INSERT ON instance_runtimes
            WHEN NEW.node_id IS NOT (SELECT node_id FROM instances WHERE id = NEW.instance_id)
                OR NEW.power_state NOT IN ('running', 'stopped')
                OR (NEW.t3_port IS NOT NULL AND (NEW.t3_port < 3800 OR NEW.t3_port > 3999))
                OR (NEW.t3_port IS NOT NULL AND (SELECT name FROM instances WHERE id = NEW.instance_id) = 'default')
                OR (NEW.clone_source_instance_id IS NOT NULL AND (
                    (SELECT name FROM instances WHERE id = NEW.instance_id) = 'default'
                    OR NOT EXISTS (
                        SELECT 1 FROM instances source
                        JOIN instances clone ON clone.id = NEW.instance_id
                        JOIN instance_runtimes source_runtime ON source_runtime.instance_id = source.id
                        WHERE source.id = NEW.clone_source_instance_id
                            AND source.name = 'default'
                            AND source.project_id = clone.project_id
                            {$sameNode}
                    )
                ))
            BEGIN SELECT RAISE(ABORT, 'Invalid Instance runtime.'); END;
            SQL);
    }
};
