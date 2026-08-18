<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @mago-expect lint:kan-defect
 */
return new class extends Migration {
    public function up(): void
    {
        if ($this->alreadyUsesInstanceOwnership()) {
            return;
        }

        $assignments = $this->bindingAssignments();

        Schema::table('app_websocket_bindings', static function (Blueprint $table): void {
            $table->unsignedBigInteger('instance_id')->nullable()->after('app_id');
        });
        Schema::table('app_analytics_bindings', static function (Blueprint $table): void {
            $table->unsignedBigInteger('instance_id')->nullable()->after('app_id');
        });

        DB::transaction(function () use ($assignments): void {
            foreach ($assignments as $assignment) {
                DB::table($assignment['table'])
                    ->where('id', $assignment['binding_id'])
                    ->update(['instance_id' => $assignment['instance_id']]);
            }
        });

        $this->replaceOwnership('app_websocket_bindings');
        $this->replaceOwnership('app_analytics_bindings');
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 2026_08_15_124510_move_app_bindings_to_instances migration is irreversible. Rolling it back would drop instance_id without restoring app_id, then a later migrate would re-run up() against the already-migrated shape. Restore from backup instead of migrate:rollback.',
        );
    }

    /**
     * @return list<array{table: string, binding_id: int, instance_id: int}>
     */
    private function bindingAssignments(): array
    {
        $assignments = [];
        $orphaned = [];
        $ambiguous = [];

        foreach (['app_websocket_bindings', 'app_analytics_bindings'] as $table) {
            foreach (DB::table($table)->select(['id', 'app_id'])->orderBy('id')->get() as $binding) {
                $bindingId = (int) $binding->id;
                $appId = (int) $binding->app_id;
                $instanceIds = DB::table('instances')
                    ->where('app_id', $appId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                if (count($instanceIds) === 1) {
                    $assignments[] = [
                        'table' => $table,
                        'binding_id' => $bindingId,
                        'instance_id' => $instanceIds[0],
                    ];

                    continue;
                }

                $detail = sprintf(
                    '%s#%d (app_id=%d, instances=%d)',
                    $table,
                    $bindingId,
                    $appId,
                    count($instanceIds),
                );

                if (! DB::table('apps')->where('id', $appId)->exists()) {
                    $orphaned[] = $detail;

                    continue;
                }

                $ambiguous[] = $detail;
            }
        }

        if ($orphaned !== [] || $ambiguous !== []) {
            throw new RuntimeException($this->blockedBindingsMessage($orphaned, $ambiguous));
        }

        return $assignments;
    }

    /**
     * @param  list<string>  $orphaned
     * @param  list<string>  $ambiguous
     */
    private function blockedBindingsMessage(array $orphaned, array $ambiguous): string
    {
        $parts = [];

        if ($orphaned !== []) {
            $parts[] =
                'Orphaned bindings whose apps are gone must be deleted: '
                .implode('; ', $orphaned)
                .'. Delete each orphaned binding, then rerun migrations.';
        }

        if ($ambiguous !== []) {
            $parts[] =
                'Instance binding ownership requires manual assignment before migration: '
                .implode('; ', $ambiguous)
                .'. Assign each legacy binding to one concrete instance, then rerun migrations.';
        }

        return implode(' ', $parts);
    }

    private function alreadyUsesInstanceOwnership(): bool
    {
        foreach (['app_websocket_bindings', 'app_analytics_bindings'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }

            if (Schema::hasColumn($table, 'app_id') || ! Schema::hasColumn($table, 'instance_id')) {
                return false;
            }
        }

        return true;
    }

    private function replaceOwnership(string $tableName): void
    {
        Schema::table($tableName, static function (Blueprint $table): void {
            $table->dropUnique(['app_id']);
            $table->dropForeign(['app_id']);
            $table->dropColumn('app_id');
        });

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->unsignedBigInteger('instance_id')->nullable(false)->change();
            $table->foreign('instance_id')->references('id')->on('instances')->cascadeOnDelete();
            $table->unique('instance_id');
        });
    }
};
