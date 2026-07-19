<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $this->assertNoReservedTldConflict();
        $this->dropTldTriggers();
        $this->createTldTriggers(reservedCondition: "\n                OR NEW.tld = 'orbit'");
    }

    public function down(): void
    {
        $this->dropTldTriggers();
        $this->createTldTriggers(reservedCondition: '');
    }

    private function dropTldTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS nodes_active_tld_required_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS nodes_active_tld_required_update');
    }

    private function assertNoReservedTldConflict(): void
    {
        /** @var list<string> $nodeNames */
        $nodeNames = DB::table('nodes')
            ->where('status', 'active')
            ->where('tld', 'orbit')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($nodeNames === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "reserved_node_tld_conflict: active node(s) [%s] use reserved TLD 'orbit'; assign each node a unique non-reserved TLD before retrying the migration.",
            implode(', ', $nodeNames),
        ));
    }

    private function createTldTriggers(string $reservedCondition): void
    {
        $invalidCondition = <<<'SQL'
            NEW.tld IS NULL
            OR NEW.tld <> trim(NEW.tld)
            OR length(NEW.tld) < 1
            OR length(NEW.tld) > 63
            OR NEW.tld <> lower(NEW.tld)
            OR NEW.tld GLOB '*[^a-z0-9-]*'
            OR substr(NEW.tld, 1, 1) = '-'
            OR substr(NEW.tld, -1, 1) = '-'
            SQL;
        $invalidCondition .= $reservedCondition;

        DB::unprepared(<<<SQL
            CREATE TRIGGER nodes_active_tld_required_insert
            BEFORE INSERT ON nodes
            WHEN NEW.status = 'active' AND (
                {$invalidCondition}
            )
            BEGIN
                SELECT RAISE(ABORT, 'active nodes require a valid TLD');
            END
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER nodes_active_tld_required_update
            BEFORE UPDATE OF status, tld ON nodes
            WHEN NEW.status = 'active' AND (
                {$invalidCondition}
            )
            BEGIN
                SELECT RAISE(ABORT, 'active nodes require a valid TLD');
            END
            SQL);
    }
};
