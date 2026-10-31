<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER instance_removals_native_sources_immutable');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER instance_removals_native_sources_immutable
            BEFORE UPDATE OF native_sources ON instance_removals
            WHEN OLD.native_sources IS NOT NULL AND NEW.native_sources IS NOT OLD.native_sources
            AND (NEW.native_sources IS NULL OR EXISTS (
                SELECT 1 FROM json_tree(OLD.native_sources) AS previous
                WHERE NOT EXISTS (
                    SELECT 1 FROM json_tree(NEW.native_sources) AS current
                    WHERE current.fullkey = previous.fullkey AND current.type = previous.type
                    AND CASE previous.type
                        WHEN 'object' THEN 1
                        WHEN 'array' THEN json_array_length(current.value) = json_array_length(previous.value)
                        ELSE current.atom IS previous.atom
                    END
                )
            ))
            BEGIN
                SELECT RAISE(ABORT, 'instance_removals.native_sources_immutable');
            END
            SQL);
    }
};
