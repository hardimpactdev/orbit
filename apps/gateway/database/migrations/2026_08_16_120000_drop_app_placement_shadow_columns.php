<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the App placement/adoption shadow columns (node_id, environment,
 * domain, path, document_root, adopted). Placement is authoritative on the app's
 * concrete Orbit Instances; this migration fail-closes: every App row must be
 * representable by a concrete Orbit Instance carrying its legacy placement before
 * the columns are dropped. Missing instances are backfilled from the legacy App
 * row; genuine ambiguity or conflict aborts the migration with the columns intact.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
return new class extends Migration {
    private const string ORBIT_CONFIG_TYPE = 'orbit_app_instance_driver_config';

    /**
     * @var list<string>
     */
    private const array SHADOW_COLUMNS = [
        'node_id',
        'environment',
        'domain',
        'path',
        'document_root',
        'adopted',
    ];

    public function up(): void
    {
        // Idempotent: once the shadow columns are gone there is nothing to
        // backfill or drop.
        if (! Schema::hasColumn('apps', 'node_id')) {
            return;
        }

        $this->assertBackfillable();

        DB::transaction(function (): void {
            $this->backfillMissingInstances();
        });

        $this->dropShadowColumns();
    }

    public function down(): void
    {
        // The legacy placement columns are reconstructed as nullable shadows;
        // authoritative data remains on instances. This is a best-effort restore
        // of the schema shape only.
        if (Schema::hasColumn('apps', 'node_id')) {
            return;
        }

        Schema::table('apps', static function (Blueprint $table): void {
            $table->unsignedBigInteger('node_id')->nullable();
            $table->string('environment')->nullable();
            $table->string('domain')->nullable();
            $table->string('path')->nullable();
            $table->string('document_root')->nullable();
            $table->boolean('adopted')->nullable();
        });
    }

    /**
     * Fail-closed pre-flight: collect every App that cannot be safely represented
     * by a concrete Orbit Instance and abort before any destructive change.
     */
    private function assertBackfillable(): void
    {
        $blocking = [];

        foreach (DB::table('apps')->get() as $app) {
            if ($this->matchingOrbitInstanceId($app) !== null) {
                continue;
            }

            $reason = $this->backfillBlocker($app);

            if ($reason !== null) {
                $blocking[] = $this->rowString($app, 'name').'#'.$this->rowInteger($app, 'id').' ('.$reason.')';
            }
        }

        if ($blocking !== []) {
            throw new RuntimeException(
                'App placement shadow removal requires a concrete Orbit instance for every app: '
                .implode('; ', $blocking)
                .'. Create the concrete instance placement for each listed app, then rerun migrations.',
            );
        }
    }

    private function backfillMissingInstances(): void
    {
        foreach (DB::table('apps')->get() as $app) {
            if ($this->matchingOrbitInstanceId($app) !== null) {
                continue;
            }

            $this->createInstanceFromLegacyPlacement($app);
        }
    }

    /**
     * The reason an app cannot be backfilled, or null when it can.
     */
    private function backfillBlocker(object $app): ?string
    {
        $nodeId = $this->rowNullableInteger($app, 'node_id');
        $path = $this->rowNullableString($app, 'path');

        if ($nodeId === null) {
            return 'legacy node_id missing';
        }

        if (DB::table('nodes')->where('id', $nodeId)->doesntExist()) {
            return "legacy node_id={$nodeId} does not exist";
        }

        if ($path === null || trim($path) === '') {
            return 'legacy path missing';
        }

        $instanceName = $this->instanceName($app);
        $existing = DB::table('instances')
            ->where('app_id', $this->rowInteger($app, 'id'))
            ->where('name', $instanceName)
            ->first();

        if ($existing !== null) {
            return "instance '{$instanceName}' already exists with divergent placement";
        }

        return null;
    }

    private function createInstanceFromLegacyPlacement(object $app): void
    {
        $nodeId = $this->rowInteger($app, 'node_id');
        $node = DB::table('nodes')->where('id', $nodeId)->first();

        DB::table('instances')->insert([
            'app_id' => $this->rowInteger($app, 'id'),
            'name' => $this->instanceName($app),
            'driver' => 'orbit',
            'php_version' => $this->rowNullableString($app, 'php_version'),
            'adopted' => (bool) ($app->adopted ?? false),
            'driver_config' => json_encode([
                'type' => self::ORBIT_CONFIG_TYPE,
                'data' => [
                    'node_id' => $nodeId,
                    'node' => is_object($node) ? $this->rowString($node, 'name') : null,
                    'path' => $this->rowString($app, 'path'),
                    'document_root' => $this->rowNullableString($app, 'document_root'),
                    'domain' => $this->rowNullableString($app, 'domain'),
                ],
            ], JSON_THROW_ON_ERROR),
            'runtime_requirements' => json_encode(
                ['php_extensions' => []],
                JSON_THROW_ON_ERROR,
            ),
            'created_at' => $app->created_at ?? now(),
            'updated_at' => $app->updated_at ?? now(),
        ]);
    }

    /**
     * The app is represented when an Orbit instance already carries its legacy
     * node_id + path placement.
     */
    private function matchingOrbitInstanceId(object $app): ?int
    {
        $nodeId = $this->rowNullableInteger($app, 'node_id');
        $path = $this->rowNullableString($app, 'path');

        if ($nodeId === null || $path === null) {
            return null;
        }

        foreach (DB::table('instances')
            ->where('app_id', $this->rowInteger($app, 'id'))
            ->where('driver', 'orbit')
            ->get() as $instance) {
            $config = $this->decodeJson($this->rowValue($instance, 'driver_config'));
            $data = is_array($config['data'] ?? null) ? $config['data'] : [];

            if (
                ($config['type'] ?? null) === self::ORBIT_CONFIG_TYPE
                && (int) ($data['node_id'] ?? 0) === $nodeId
                && (string) ($data['path'] ?? '') === $path
            ) {
                return (int) $this->rowValue($instance, 'id');
            }
        }

        return null;
    }

    private function instanceName(object $app): string
    {
        $environment = $this->rowNullableString($app, 'environment');

        return $environment !== null && trim($environment) !== '' ? trim($environment) : 'default';
    }

    private function dropShadowColumns(): void
    {
        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropForeign(['node_id']);
        });

        // Drop every index that references a shadow column before the columns
        // themselves; SQLite rebuilds the table on column drop and rejects the
        // rebuild while a lingering index (e.g. the domain unique) still names a
        // removed column.
        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropUnique(['domain']);
            $table->dropIndex(['node_id', 'name']);
            $table->dropIndex(['node_id', 'environment']);
        });

        Schema::table('apps', static function (Blueprint $table): void {
            $table->dropColumn(self::SHADOW_COLUMNS);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function rowValue(object $row, string $key): mixed
    {
        return $row->{$key} ?? null;
    }

    private function rowInteger(object $row, string $key): int
    {
        return (int) ($row->{$key} ?? 0);
    }

    private function rowNullableInteger(object $row, string $key): ?int
    {
        $value = $row->{$key} ?? null;

        return $value === null ? null : (int) $value;
    }

    private function rowString(object $row, string $key): string
    {
        return (string) ($row->{$key} ?? '');
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = $row->{$key} ?? null;

        return $value === null ? null : (string) $value;
    }
};
