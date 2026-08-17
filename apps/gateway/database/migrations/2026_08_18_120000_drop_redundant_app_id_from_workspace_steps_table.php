<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('workspace_steps', 'app_id')) {
            return;
        }

        $offendingIds = DB::table('workspace_steps')
            ->leftJoin('instances', 'instances.id', '=', 'workspace_steps.instance_id')
            ->where(static function (Builder $query): void {
                $query
                    ->whereNull('instances.id')
                    ->orWhereColumn('workspace_steps.app_id', '!=', 'instances.app_id');
            })
            ->orderBy('workspace_steps.id')
            ->pluck('workspace_steps.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($offendingIds !== []) {
            throw new RuntimeException(
                'workspace_steps.app_id disagrees with instance.app_id or the instance is missing for step id(s): '
                    .implode(', ', $offendingIds),
            );
        }

        if (Schema::hasIndex('workspace_steps', ['app_id', 'phase', 'sort_order'])) {
            Schema::table('workspace_steps', static function (Blueprint $table): void {
                $table->dropIndex(['app_id', 'phase', 'sort_order']);
            });
        }

        $hasAppIdForeign = false;

        foreach (Schema::getForeignKeys('workspace_steps') as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === ['app_id']) {
                $hasAppIdForeign = true;
                break;
            }
        }

        Schema::table('workspace_steps', static function (Blueprint $table) use ($hasAppIdForeign): void {
            if ($hasAppIdForeign) {
                $table->dropForeign(['app_id']);
            }

            $table->dropColumn('app_id');
        });
    }
};
