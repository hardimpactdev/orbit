<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string Original = "(OLD.status = 'removing' AND NEW.status <> 'removing')";

    private const string Restorable = <<<'SQL'
(OLD.status = 'removing' AND NEW.status <> 'removing' AND NOT EXISTS (
    SELECT 1 FROM instance_removal_members
    JOIN instance_removals ON instance_removals.id = instance_removal_members.instance_removal_id
    WHERE instance_removal_members.instance_id = NEW.id
      AND instance_removal_members.row_deleted_at IS NULL
      AND instance_removals.status = 'failed'
      AND instance_removals.error_code IN ('instance.remove_refused', 'instance.worktree_dependents')
      AND NEW.status = COALESCE(json_extract(instance_removals.native_intent, '$.destroy_previous_status'), 'active')
))
SQL;

    public function up(): void
    {
        $this->replace(self::Original, self::Restorable);
    }

    public function down(): void
    {
        $this->replace(self::Restorable, self::Original);
    }

    private function replace(string $before, string $after): void
    {
        $trigger = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'instances_removal_status_update'");
        $sql = $trigger instanceof stdClass ? ($trigger->sql ?? null) : null;
        if (! is_string($sql) || substr_count($sql, $before) !== 1) {
            throw new RuntimeException('The Instance removal status trigger does not match its migration contract.');
        }
        DB::transaction(static function () use ($sql, $before, $after): void {
            DB::unprepared('DROP TRIGGER instances_removal_status_update');
            DB::getPdo()->exec(str_replace($before, $after, $sql));
        });
    }
};
