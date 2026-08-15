<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
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

    /**
     * @return list<array{table: string, binding_id: int, instance_id: int}>
     */
    private function bindingAssignments(): array
    {
        $assignments = [];
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

                if (count($instanceIds) !== 1) {
                    $ambiguous[] = sprintf(
                        '%s#%d (app_id=%d, instances=%d)',
                        $table,
                        $bindingId,
                        $appId,
                        count($instanceIds),
                    );

                    continue;
                }

                $assignments[] = [
                    'table' => $table,
                    'binding_id' => $bindingId,
                    'instance_id' => $instanceIds[0],
                ];
            }
        }

        if ($ambiguous !== []) {
            throw new RuntimeException(
                'Instance binding ownership requires manual assignment before migration: '
                .implode('; ', $ambiguous)
                .'. Assign each legacy binding to one concrete instance, then rerun migrations.',
            );
        }

        return $assignments;
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
