<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the existing failed-attempt, placement and member ownership predicates.
        // Their app-dev-only role check prevented recovery of failed production clones.
        foreach (['instance_removals_insert', 'instance_removal_members_insert', 'instances_removal_status_update'] as $name) {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
            if (! $row instanceof stdClass || ! is_string($row->sql) || ! str_contains($row->sql, "node_roles.role = 'app-dev'")) {
                throw new LogicException('The failed Instance removal ownership trigger is missing.');
            }
            $sql = str_replace("node_roles.role = 'app-dev'", "node_roles.role IN ('app-dev', 'app-prod')", $row->sql);
            DB::unprepared('DROP TRIGGER '.$name);
            DB::connection()->getPdo()->exec($sql);
        }
    }
};
