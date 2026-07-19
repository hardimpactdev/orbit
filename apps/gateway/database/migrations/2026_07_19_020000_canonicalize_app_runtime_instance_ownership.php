<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * This one-shot canonicalization moves worker and setup ownership to app instances.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
return new class extends Migration {
    public function up(): void
    {
        $this->assertUnambiguousOwnership();

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->boolean('worker_enabled')->default(false)->after('runtime_requirements');
            $table->json('worker_config')->nullable()->after('worker_enabled');
        });

        Schema::table('app_setup_steps', static function (Blueprint $table): void {
            $table->unsignedBigInteger('app_instance_id')->nullable()->after('app_id');
        });

        Schema::table('app_setup_runs', static function (Blueprint $table): void {
            $table->unsignedBigInteger('app_instance_id')->nullable()->after('app_id');
        });

        DB::transaction(function (): void {
            foreach ($this->affectedApps() as $app) {
                $instanceId = $this->provenAppInstanceId($app['id']) ?? throw new RuntimeException(
                    "Canonical app runtime ownership could not resolve app_id={$app['id']} after validation.",
                );

                DB::table('app_instances')
                    ->where('id', $instanceId)
                    ->update([
                        'worker_enabled' => $app['worker_enabled'],
                        'worker_config' => $app['worker_config'],
                    ]);

                DB::table('app_setup_steps')
                    ->where('app_id', $app['id'])
                    ->update(['app_instance_id' => $instanceId]);

                DB::table('app_setup_runs')
                    ->where('app_id', $app['id'])
                    ->update(['app_instance_id' => $instanceId]);
            }
        });

        $this->replaceSetupStepOwnership();
        $this->replaceSetupRunOwnership();

        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropColumn(['worker_enabled', 'worker_config']);
        });
    }

    private function assertUnambiguousOwnership(): void
    {
        $ambiguous = array_values(array_filter(
            $this->affectedApps(),
            fn (array $app): bool => $this->provenAppInstanceId($app['id']) === null,
        ));

        if ($ambiguous === []) {
            return;
        }

        $details = implode('; ', array_map(static fn (array $app): string => sprintf(
            '%s#%d (instances=%d, setup_steps=%d, setup_runs=%d, worker=%s, worker_config=%s)',
            $app['name'],
            $app['id'],
            $app['instance_count'],
            $app['setup_step_count'],
            $app['setup_run_count'],
            $app['worker_enabled'] ? 'enabled' : 'disabled',
            $app['worker_config'] === null ? 'none' : 'set',
        ), $ambiguous));

        throw new RuntimeException(
            "Canonical app runtime ownership requires manual assignment before migration: {$details}. "
            .'Assign worker and setup state to one concrete app instance, then rerun migrations.',
        );
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     worker_enabled: bool,
     *     worker_config: string|null,
     *     instance_count: int,
     *     setup_step_count: int,
     *     setup_run_count: int,
     * }>
     */
    private function affectedApps(): array
    {
        $affected = [];
        $apps = DB::table('apps')
            ->select(['apps.id', 'apps.name', 'apps.worker_enabled', 'apps.worker_config'])
            ->selectRaw('(select count(*) from app_instances where app_instances.app_id = apps.id) as instance_count')
            ->selectRaw(
                '(select count(*) from app_setup_steps where app_setup_steps.app_id = apps.id) as setup_step_count',
            )
            ->selectRaw(
                '(select count(*) from app_setup_runs where app_setup_runs.app_id = apps.id) as setup_run_count',
            )
            ->get();

        foreach ($apps as $app) {
            $typed = [
                'id' => $this->rowInteger($app, 'id'),
                'name' => $this->rowString($app, 'name'),
                'worker_enabled' => $this->rowBoolean($app, 'worker_enabled'),
                'worker_config' => $this->rowNullableString($app, 'worker_config'),
                'instance_count' => $this->rowInteger($app, 'instance_count'),
                'setup_step_count' => $this->rowInteger($app, 'setup_step_count'),
                'setup_run_count' => $this->rowInteger($app, 'setup_run_count'),
            ];

            if (
                $typed['worker_enabled']
                || $typed['worker_config'] !== null
                || $typed['setup_step_count'] > 0
                || $typed['setup_run_count'] > 0
            ) {
                $affected[] = $typed;
            }
        }

        return $affected;
    }

    private function provenAppInstanceId(int $appId): ?int
    {
        $ids = DB::table('app_instances')
            ->where('app_id', $appId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if (count($ids) === 1) {
            return $ids[0];
        }

        $app = DB::table('apps')->where('id', $appId)->first();

        if (! is_object($app)) {
            return null;
        }

        $nodeId = $this->rowInteger($app, 'node_id');
        $path = $this->rowString($app, 'path');

        $matching = DB::table('app_instances')
            ->where('app_id', $appId)
            ->where('driver', 'orbit')
            ->orderBy('id')
            ->get()
            ->filter(function (object $instance) use ($nodeId, $path): bool {
                $config = $this->decodeJson($this->rowValue($instance, 'driver_config'));
                $data = is_array($config['data'] ?? null) ? $config['data'] : [];

                return (
                    ($config['type'] ?? null) === 'orbit_app_instance_driver_config'
                    && (int) ($data['node_id'] ?? 0) === $nodeId
                    && ($data['path'] ?? null) === $path
                );
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return count($matching) === 1 ? $matching[0] : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function replaceSetupStepOwnership(): void
    {
        Schema::table('app_setup_steps', static function (Blueprint $table): void {
            $table->dropUnique(['app_id', 'sort_order']);
            $table->dropForeign(['app_id']);
            $table->dropColumn('app_id');
        });

        Schema::table('app_setup_steps', static function (Blueprint $table): void {
            $table->unsignedBigInteger('app_instance_id')->nullable(false)->change();
            $table->foreign('app_instance_id')->references('id')->on('app_instances')->cascadeOnDelete();
            $table->unique(['app_instance_id', 'sort_order']);
        });
    }

    private function replaceSetupRunOwnership(): void
    {
        Schema::table('app_setup_runs', static function (Blueprint $table): void {
            $table->dropIndex(['app_id', 'status']);
            $table->dropForeign(['app_id']);
            $table->dropColumn('app_id');
        });

        Schema::table('app_setup_runs', static function (Blueprint $table): void {
            $table->unsignedBigInteger('app_instance_id')->nullable(false)->change();
            $table->foreign('app_instance_id')->references('id')->on('app_instances')->cascadeOnDelete();
            $table->index(['app_instance_id', 'status']);
        });
    }

    private function rowInteger(object $row, string $field): int
    {
        $value = $this->rowValue($row, $field);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException("Canonical app runtime ownership row has an invalid {$field} integer.");
    }

    private function rowBoolean(object $row, string $field): bool
    {
        $value = $this->rowValue($row, $field);

        return $value === true || $value === 1 || $value === '1';
    }

    private function rowString(object $row, string $field): string
    {
        $value = $this->rowValue($row, $field);

        if (! is_string($value)) {
            throw new RuntimeException("Canonical app runtime ownership row has an invalid {$field} string.");
        }

        return $value;
    }

    private function rowNullableString(object $row, string $field): ?string
    {
        $value = $this->rowValue($row, $field);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException("Canonical app runtime ownership row has an invalid {$field} string.");
        }

        return $value;
    }

    private function rowValue(object $row, string $field): mixed
    {
        return get_object_vars($row)[$field] ?? null;
    }
};
