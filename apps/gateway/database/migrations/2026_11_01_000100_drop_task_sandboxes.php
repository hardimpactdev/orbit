<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the old task sandbox lane: the `task_sandboxes` table, `instances.task_sandbox_id`,
 * `nodes.compute_sandbox_id`, `task_checks.task_sandbox_id`, and the removal trigger clauses that read them. Task VMs
 * replaced the lane, and every sandbox row is destroyed. The columns are dropped in place, because rebuilding
 * `instances` or `nodes` would invalidate the cross-table triggers. Rolling back runs the original migrations again,
 * so it restores the schema, not the rows.
 */
return new class extends Migration
{
    /** The migrations that built the lane, in the order they ran. */
    private const array Originals = [
        '2026_10_07_000200_create_task_sandboxes_table.php',
        '2026_10_07_064540_add_fleet_enrollment_to_task_sandboxes.php',
        '2026_10_07_093102_add_pi_ready_at_to_task_sandboxes.php',
        '2026_10_07_110449_add_task_sandbox_id_to_task_checks.php',
        '2026_10_08_102823_add_warm_pool_to_task_sandboxes.php',
        '2026_10_12_000200_add_task_sandbox_to_instances.php',
        '2026_10_12_000300_add_pi_token_to_task_sandboxes_table.php',
        '2026_10_12_000400_scope_instance_checkout_uniqueness_to_shared_workspaces.php',
        '2026_10_12_000500_add_model_key_to_task_sandboxes_table.php',
        '2026_10_12_000600_add_review_timing_to_task_sandboxes_table.php',
        '2026_10_12_000700_add_resume_intent_to_task_sandboxes_table.php',
        '2026_10_19_000000_allow_owned_project_sandbox_instance_removal.php',
    ];

    public function up(): void
    {
        if (DB::table('task_sandboxes')->where('state', '!=', 'destroyed')->exists()
            || DB::table('instances')->whereNotNull('task_sandbox_id')->exists()
            || DB::table('nodes')->whereNotNull('compute_sandbox_id')->exists()) {
            throw new RuntimeException('Destroy the old task sandboxes before dropping their schema.');
        }

        DB::transaction(function (): void {
            $this->dropTriggerClauses();
            DB::statement('DROP INDEX instances_node_id_checkout_path_unique');
            DB::statement('CREATE UNIQUE INDEX instances_node_id_checkout_path_unique ON instances (node_id, checkout_path)');
            DB::statement('DROP INDEX instances_task_sandbox_id_unique');
            DB::statement('ALTER TABLE instances DROP COLUMN task_sandbox_id');
            DB::statement('DROP INDEX nodes_compute_sandbox_id_unique');
            DB::statement('ALTER TABLE nodes DROP COLUMN compute_sandbox_id');
            DB::statement('DROP INDEX task_checks_task_sandbox_id_index');
            DB::statement('ALTER TABLE task_checks DROP COLUMN task_sandbox_id');
            Schema::drop('task_sandboxes');
        });
    }

    public function down(): void
    {
        foreach (self::Originals as $file) {
            $migration = require database_path("migrations/{$file}");
            $migration->up();
        }
    }

    /** Reverses `allow_owned_project_sandbox_instance_removal`, whose removal evidence stays. */
    private function dropTriggerClauses(): void
    {
        $root = 'OR (NEW.root IS NULL AND NOT EXISTS (SELECT 1 FROM instances'
            .' WHERE instances.id = NEW.instance_id AND instances.task_workspace_routed = 0'
            ." AND NEW.source_identity = 'sandbox:' || instances.task_sandbox_id AND "
            .$this->ownedSandbox('instances').'))';
        $this->replace('instance_removal_members_insert', $root, 'OR NEW.root IS NULL', 1);
        foreach ([
            ['instance_removals_insert', 'instances', 1],
            ['instance_removal_members_insert', 'instances', 2],
            ['instances_removal_status_update', 'OLD', 1],
        ] as [$trigger, $row, $count]) {
            $failed = "{$row}.failed_step IS NOT NULL AND {$row}.error_code IS NOT NULL";
            $this->replace($trigger, '('.$failed.' OR '.$this->ownedSandbox($row).')', $failed, $count);
        }
        if (DB::table('sqlite_master')->where('type', 'trigger')->where('sql', 'like', '%sandbox%')->exists()) {
            throw new RuntimeException('A trigger still reads the old task sandbox schema.');
        }
    }

    private function ownedSandbox(string $row): string
    {
        return "({$row}.source_layout = 'checkout' AND {$row}.checkout_path = '/home/orbit/orbit'"
            ." AND {$row}.task_workspace_routed IS NOT NULL"
            .' AND EXISTS (SELECT 1 FROM task_sandboxes'
            .' JOIN tasks ON tasks.id = task_sandboxes.group_id'
            .' JOIN projects ON projects.id = tasks.project_id'
            .' JOIN nodes ON nodes.id = task_sandboxes.node_id'
            .' JOIN node_roles ON node_roles.node_id = nodes.id'
            ." WHERE task_sandboxes.id = {$row}.task_sandbox_id"
            ." AND task_sandboxes.node_id = {$row}.node_id AND nodes.compute_sandbox_id = task_sandboxes.id"
            ." AND task_sandboxes.provider IN ('incus', 'upcloud') AND task_sandboxes.enrollment IS NOT NULL"
            ." AND task_sandboxes.desired_power = 'destroyed' AND task_sandboxes.model_key IS NULL"
            ." AND tasks.parent_id IS NULL AND tasks.task_compute = 'vm' AND tasks.execution_mode = 'managed'"
            ." AND tasks.project_id = {$row}.project_id AND projects.slug <> 'orbit'"
            ." AND tasks.taskable_type = 'instance' AND tasks.taskable_id = {$row}.id"
            ." AND {$row}.name = 'task-' || tasks.id AND {$row}.branch_override = {$row}.name"
            ." AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing')))";
    }

    /**
     * Replaces the clause, matched across any whitespace. A trigger without it is left alone, as on a schema that
     * never ran the sandbox migration. Any other count than none or `$count` fails.
     */
    private function replace(string $trigger, string $clause, string $replacement, int $count): void
    {
        $tokens = preg_split('/\s+/', trim($clause), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $pattern = '/'.implode('\s+', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)).'/';
        $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger)->value('sql');
        $found = is_string($sql) ? preg_match_all($pattern, $sql) : false;
        if ($found === 0) {
            return;
        }
        if (! is_string($sql) || $found !== $count) {
            throw new RuntimeException("The {$trigger} trigger is incompatible.");
        }
        $updated = preg_replace_callback($pattern, static fn (): string => $replacement, $sql);
        if (! is_string($updated)) {
            throw new RuntimeException("The {$trigger} trigger cannot be updated.");
        }
        DB::statement("DROP TRIGGER {$trigger}");
        DB::statement($updated);
    }
};
