<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_removals', function (Blueprint $table): void {
            $table->json('native_sources')->nullable();
        });
        $capture = "(OLD.origin IS NULL AND OLD.worktree_inventory IS NULL AND (SELECT native_sources IS NULL AND COALESCE(json_extract(native_intent, '$.source_pending'), 0) = 1 FROM instance_removals WHERE id = OLD.instance_removal_id))";
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'instance_removal_members_immutable'");
        if (! is_object($row) || ! is_string($row->sql)) {
            throw new LogicException('The Instance removal member trigger is missing.');
        }
        $sql = $row->sql;
        foreach ([
            'NEW.common_repository_path IS NOT OLD.common_repository_path',
            'NEW.source_identity IS NOT OLD.source_identity',
            'NEW.linked_worktree_paths <> OLD.linked_worktree_paths',
            'NEW.source_digest <> OLD.source_digest',
        ] as $condition) {
            $sql = str_replace($condition, "({$condition}) AND NOT {$capture}", $sql);
        }
        DB::unprepared('DROP TRIGGER instance_removal_members_immutable');
        DB::unprepared($sql);
        DB::unprepared(<<<SQL
            DROP TRIGGER instance_removal_members_source_commit_immutable;
            CREATE TRIGGER instance_removal_members_source_commit_immutable
            BEFORE UPDATE OF source_commit ON instance_removal_members
            WHEN NEW.source_commit IS NOT OLD.source_commit AND NOT {$capture}
            BEGIN
                SELECT RAISE(ABORT, 'Native Instance removal source commit is immutable.');
            END;
            DROP TRIGGER instance_removal_members_source_immutable;
            CREATE TRIGGER instance_removal_members_source_immutable
            BEFORE UPDATE OF origin, worktree_inventory ON instance_removal_members
            WHEN (NEW.origin IS NOT OLD.origin OR NEW.worktree_inventory IS NOT OLD.worktree_inventory)
                AND NOT {$capture}
            BEGIN
                SELECT RAISE(ABORT, 'Native Instance removal source inventory is immutable.');
            END;
            CREATE TRIGGER instance_removals_native_sources_immutable
            BEFORE UPDATE OF native_sources ON instance_removals
            WHEN OLD.native_sources IS NOT NULL AND NEW.native_sources IS NOT OLD.native_sources
            BEGIN
                SELECT RAISE(ABORT, 'Native Instance removal source inspection is immutable.');
            END;
            SQL);
    }
};
