<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * This one-shot canonicalization validates and migrates every deployment-owned historical row.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
return new class extends Migration {
    public function up(): void
    {
        $this->assertUnambiguousOwnership();

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->json('deploy_warmup_paths')->nullable()->after('runtime_requirements');
        });

        Schema::table('deploy_steps', static function (Blueprint $table): void {
            $table->unsignedBigInteger('app_instance_id')->nullable()->after('app_id');
        });

        Schema::table('deployment_runs', static function (Blueprint $table): void {
            $table->unsignedBigInteger('app_instance_id')->nullable()->after('app_id');
        });

        DB::transaction(function (): void {
            foreach ($this->affectedApps() as $app) {
                $instanceId = $this->soleInstanceId($app['id']);

                DB::table('deploy_steps')
                    ->where('app_id', $app['id'])
                    ->update(['app_instance_id' => $instanceId]);

                DB::table('deployment_runs')
                    ->where('app_id', $app['id'])
                    ->update(['app_instance_id' => $instanceId]);

                DB::table('app_instances')
                    ->where('id', $instanceId)
                    ->update([
                        'deploy_warmup_paths' => $app['deploy_warmup_paths'],
                        'latest_deployment_status' => $app['latest_deployment_status'],
                        'latest_deployment_run_id' => $app['latest_deployment_run_id'],
                    ]);
            }
        });

        $this->replaceDeployStepOwnership();
        $this->replaceDeploymentRunOwnership();

        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropColumn([
                'deploy_warmup_paths',
                'latest_deployment_status',
                'latest_deployment_run_id',
            ]);
        });
    }

    private function assertUnambiguousOwnership(): void
    {
        $ambiguous = array_values(array_filter(
            $this->affectedApps(),
            static fn (array $app): bool => $app['instance_count'] !== 1,
        ));

        if ($ambiguous === []) {
            return;
        }

        $details = implode(
            '; ',
            array_map(static fn (array $app): string => sprintf(
                '%s#%d (instances=%d, deploy_steps=%d, deployment_runs=%d, warmup_policy=%s, latest_status=%s)',
                $app['name'],
                $app['id'],
                $app['instance_count'],
                $app['deploy_step_count'],
                $app['deployment_run_count'],
                $app['deploy_warmup_paths'] === null ? 'none' : 'set',
                $app['latest_deployment_status'] ?? 'none',
            ), $ambiguous),
        );

        throw new RuntimeException(
            "Canonical deployment ownership requires manual assignment before migration: {$details}. "
            .'Resolve every listed app to one concrete deployment-owning app instance, then rerun migrations.',
        );
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     deploy_warmup_paths: string|null,
     *     latest_deployment_status: string|null,
     *     latest_deployment_run_id: int|null,
     *     instance_count: int,
     *     deploy_step_count: int,
     *     deployment_run_count: int,
     * }>
     */
    private function affectedApps(): array
    {
        $affected = [];

        $apps = DB::table('apps')
            ->select([
                'apps.id',
                'apps.name',
                'apps.deploy_warmup_paths',
                'apps.latest_deployment_status',
                'apps.latest_deployment_run_id',
            ])
            ->selectRaw('(select count(*) from app_instances where app_instances.app_id = apps.id) as instance_count')
            ->selectRaw('(select count(*) from deploy_steps where deploy_steps.app_id = apps.id) as deploy_step_count')
            ->selectRaw(
                '(select count(*) from deployment_runs where deployment_runs.app_id = apps.id) as deployment_run_count',
            )
            ->get();

        foreach ($apps as $app) {
            $typed = [
                'id' => $this->rowInteger($app, 'id'),
                'name' => $this->rowString($app, 'name'),
                'deploy_warmup_paths' => $this->rowNullableString($app, 'deploy_warmup_paths'),
                'latest_deployment_status' => $this->rowNullableString($app, 'latest_deployment_status'),
                'latest_deployment_run_id' => $this->rowNullableInteger($app, 'latest_deployment_run_id'),
                'instance_count' => $this->rowInteger($app, 'instance_count'),
                'deploy_step_count' => $this->rowInteger($app, 'deploy_step_count'),
                'deployment_run_count' => $this->rowInteger($app, 'deployment_run_count'),
            ];

            $hasState =
                $typed['deploy_step_count'] > 0
                || $typed['deployment_run_count'] > 0
                || $typed['deploy_warmup_paths'] !== null
                || $typed['latest_deployment_status'] !== null
                || $typed['latest_deployment_run_id'] !== null;

            if ($hasState) {
                $affected[] = $typed;
            }
        }

        return $affected;
    }

    private function soleInstanceId(int $appId): int
    {
        $ids = array_values(
            DB::table('app_instances')
                ->where('app_id', $appId)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );

        if (count($ids) !== 1) {
            throw new RuntimeException(
                "Canonical deployment ownership expected exactly one instance for app_id={$appId}; found "
                .count($ids)
                .'.',
            );
        }

        return $ids[0];
    }

    private function replaceDeployStepOwnership(): void
    {
        Schema::table('deploy_steps', static function (Blueprint $table): void {
            $table->dropUnique(['app_id', 'sort_order']);
            $table->dropIndex(['app_id', 'title']);
            $table->dropForeign(['app_id']);
            $table->dropColumn('app_id');
        });

        Schema::table('deploy_steps', static function (Blueprint $table): void {
            $table->unsignedBigInteger('app_instance_id')->nullable(false)->change();
            $table
                ->foreign('app_instance_id')
                ->references('id')
                ->on('app_instances')
                ->cascadeOnDelete();
            $table->unique(['app_instance_id', 'sort_order']);
            $table->index(['app_instance_id', 'title']);
        });
    }

    private function replaceDeploymentRunOwnership(): void
    {
        Schema::table('deployment_runs', static function (Blueprint $table): void {
            $table->dropIndex(['app_id', 'started_at']);
            $table->dropIndex(['app_id', 'status']);
            $table->dropForeign(['app_id']);
            $table->dropColumn('app_id');
        });

        Schema::table('deployment_runs', static function (Blueprint $table): void {
            $table->unsignedBigInteger('app_instance_id')->nullable(false)->change();
            $table
                ->foreign('app_instance_id')
                ->references('id')
                ->on('app_instances')
                ->cascadeOnDelete();
            $table->index(['app_instance_id', 'started_at']);
            $table->index(['app_instance_id', 'status']);
        });
    }

    private function rowInteger(object $row, string $field): int
    {
        $values = get_object_vars($row);

        if (is_int($values[$field] ?? null)) {
            return $values[$field];
        }

        if (is_string($values[$field] ?? null) && ctype_digit($values[$field])) {
            return (int) $values[$field];
        }

        throw new RuntimeException("Canonical deployment row has an invalid {$field} integer.");
    }

    private function rowNullableInteger(object $row, string $field): ?int
    {
        $values = get_object_vars($row);

        if (($values[$field] ?? null) === null) {
            return null;
        }

        return $this->rowInteger($row, $field);
    }

    private function rowString(object $row, string $field): string
    {
        $values = get_object_vars($row);

        if (! is_string($values[$field] ?? null)) {
            throw new RuntimeException("Canonical deployment row has an invalid {$field} string.");
        }

        return $values[$field];
    }

    private function rowNullableString(object $row, string $field): ?string
    {
        $values = get_object_vars($row);

        if (($values[$field] ?? null) === null) {
            return null;
        }

        return $this->rowString($row, $field);
    }
};
