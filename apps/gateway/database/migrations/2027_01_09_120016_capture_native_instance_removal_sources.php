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
        DB::unprepared('DROP TRIGGER instance_removal_members_immutable');
        DB::unprepared(<<<SQL
            CREATE TRIGGER `instance_removal_members_immutable`
            BEFORE UPDATE ON instance_removal_members
            WHEN NEW.instance_removal_id <> OLD.instance_removal_id
                OR NEW.position <> OLD.position
                OR NEW.instance_id <> OLD.instance_id
                OR NEW.project_id <> OLD.project_id
                OR NEW.node_id <> OLD.node_id
                OR NEW.route_id IS NOT OLD.route_id
                            OR NEW.runtime_published <> OLD.runtime_published
                OR NEW.name <> OLD.name
                OR NEW.environment <> OLD.environment
                OR NEW.source_layout <> OLD.source_layout
                OR NEW.repository_identity IS NOT OLD.repository_identity
                OR NEW.checkout_path IS NOT OLD.checkout_path
                OR NEW.root IS NOT OLD.root
                OR NEW.branch IS NOT OLD.branch
                OR NEW.starting_commit IS NOT OLD.starting_commit
                OR (NEW.common_repository_path IS NOT OLD.common_repository_path) AND NOT {$capture}
                OR (NEW.source_identity IS NOT OLD.source_identity) AND NOT {$capture}
                OR (NEW.linked_worktree_paths <> OLD.linked_worktree_paths) AND NOT {$capture}
                OR (NEW.source_digest <> OLD.source_digest) AND NOT {$capture}
                OR (OLD.source_prepared_at IS NOT NULL AND NEW.source_prepared_at IS NOT OLD.source_prepared_at)
                OR (OLD.route_cleared_at IS NOT NULL AND NEW.route_cleared_at IS NOT OLD.route_cleared_at)
                OR (OLD.route_outcome IS NOT NULL AND NEW.route_outcome IS NOT OLD.route_outcome)
                OR (OLD.source_finalized_at IS NOT NULL AND NEW.source_finalized_at IS NOT OLD.source_finalized_at)
                OR (OLD.finalization_receipt IS NOT NULL
                    AND NEW.finalization_receipt IS NOT OLD.finalization_receipt)
                OR (OLD.runtime_cleaned_at IS NOT NULL AND NEW.runtime_cleaned_at IS NOT OLD.runtime_cleaned_at)
                OR (OLD.row_deleted_at IS NOT NULL AND NEW.row_deleted_at IS NOT OLD.row_deleted_at)
                OR (OLD.source_prepared_at IS NULL AND NEW.source_prepared_at IS NOT NULL AND (
                    (SELECT status FROM instances WHERE id = NEW.instance_id) IS NOT 'removing'
                    OR (SELECT status FROM instance_removals
                        WHERE id = NEW.instance_removal_id) IS NOT 'removing'
                ))
                OR (NEW.route_cleared_at IS NOT NULL AND NEW.source_prepared_at IS NULL)
                OR (NEW.route_outcome IS NOT NULL) <> (NEW.route_cleared_at IS NOT NULL)
                OR (NEW.route_outcome IS NOT NULL AND NEW.route_outcome NOT IN ('retained', 'deleted', 'none'))
                            OR (NEW.route_outcome IS NOT NULL AND (NEW.route_outcome = 'none') <> (NEW.route_id IS NULL))
                OR (NEW.route_cleared_at IS NOT NULL AND EXISTS (
                    SELECT 1 FROM route_targets WHERE instance_id = NEW.instance_id
                ))
                OR (NEW.route_outcome = 'deleted' AND EXISTS (
                    SELECT 1 FROM routes WHERE id = NEW.route_id
                ))
                OR (NEW.route_outcome = 'retained' AND NOT EXISTS (
                    SELECT 1 FROM routes WHERE id = NEW.route_id
                ))
                OR (NEW.source_finalized_at IS NOT NULL AND NEW.route_cleared_at IS NULL)
                OR (NEW.finalization_receipt IS NOT NULL) <> (NEW.source_finalized_at IS NOT NULL)
                OR (NEW.runtime_cleaned_at IS NOT NULL AND NEW.source_finalized_at IS NULL)
                OR (NEW.row_deleted_at IS NOT NULL AND NEW.runtime_cleaned_at IS NULL)
                OR (NEW.row_deleted_at IS NOT NULL AND EXISTS (
                    SELECT 1 FROM instances WHERE id = NEW.instance_id
                ))
                OR (SELECT status FROM instance_removals
                    WHERE id = NEW.instance_removal_id) = 'completed'
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Instance removal member contract.');
            END;
            SQL);
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
