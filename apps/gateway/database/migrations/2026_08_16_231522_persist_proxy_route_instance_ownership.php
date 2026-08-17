<?php

declare(strict_types=1);

use App\Services\Apps\AppProxyRouteRuntimeUpstreamBackfill;
use App\Services\Proxy\InstanceProxyRouteOwnershipResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
return new class extends Migration {
    public function up(): void
    {
        $assignments = $this->assignments();

        if (! Schema::hasColumn('proxy_routes', 'instance_id')) {
            Schema::table('proxy_routes', static function (Blueprint $table): void {
                $table
                    ->foreignId('instance_id')
                    ->nullable()
                    ->after('workspace_id')
                    ->constrained('instances')
                    ->nullOnDelete();
                $table->index(['instance_id', 'owner_type']);
            });
        }

        DB::transaction(function () use ($assignments): void {
            foreach ($assignments as $routeId => $instanceId) {
                DB::table('proxy_routes')
                    ->where('id', $routeId)
                    ->update(['instance_id' => $instanceId]);
            }
        });

        app(AppProxyRouteRuntimeUpstreamBackfill::class)->run(DB::getDefaultConnection());
    }

    public function down(): void
    {
        if (! Schema::hasColumn('proxy_routes', 'instance_id')) {
            return;
        }

        Schema::table('proxy_routes', static function (Blueprint $table): void {
            $table->dropIndex(['instance_id', 'owner_type']);
            $table->dropConstrainedForeignId('instance_id');
        });
    }

    /** @return array<int, int|null> */
    private function assignments(): array
    {
        $assignments = [];
        $instanceColumnExists = Schema::hasColumn('proxy_routes', 'instance_id');
        $this->assertLegacyJsonIsValid();

        foreach (DB::table('proxy_routes')->orderBy('id')->get() as $route) {
            $ownerType = $this->rowString($route, 'owner_type');
            $kind = $this->rowString($route, 'kind');
            $routeId = $this->rowInteger($route, 'id');

            $expectedKind = InstanceProxyRouteOwnershipResolver::expectedKind($ownerType);

            if ($expectedKind !== null && $kind !== $expectedKind) {
                throw $this->ownershipException(
                    $route,
                    "requires kind='{$expectedKind}' but found kind='{$kind}'",
                );
            }

            if ($expectedKind === null) {
                $assignments[$routeId] = null;

                continue;
            }

            if (
                InstanceProxyRouteOwnershipResolver::isDirectOwner($ownerType)
                && $this->rowNullableInteger($route, 'workspace_id') !== null
            ) {
                throw $this->ownershipException($route, 'must not identify a workspace_id');
            }

            $assignments[$routeId] = $ownerType === 'workspace'
                ? $this->workspaceInstanceId($route)
                : $this->configuredInstanceId($route, $instanceColumnExists);
        }

        return $assignments;
    }

    private function workspaceInstanceId(object $route): int
    {
        $workspaceId = $this->rowNullableInteger($route, 'workspace_id');

        if ($workspaceId === null) {
            throw $this->ownershipException($route, 'has no workspace_id');
        }

        $workspace = DB::table('workspaces')->where('id', $workspaceId)->first();

        if (! is_object($workspace)) {
            throw $this->ownershipException($route, "identifies missing workspace_id={$workspaceId}");
        }

        $instanceId = $this->rowNullableInteger($workspace, 'instance_id');

        if ($instanceId === null || DB::table('instances')->where('id', $instanceId)->doesntExist()) {
            $value = $instanceId === null ? 'null' : (string) $instanceId;

            throw $this->ownershipException(
                $route,
                "workspace_id={$workspaceId} identifies missing instance_id={$value}",
            );
        }

        $this->assertWorkspaceAppIdentityMatches($route, $workspace, $instanceId);
        $this->assertAppIdentityMatches($route, $instanceId);
        $this->assertConfiguredIdsMatch($route, $instanceId);

        return $instanceId;
    }

    private function assertWorkspaceAppIdentityMatches(object $route, object $workspace, int $instanceId): void
    {
        $workspaceId = $this->rowInteger($workspace, 'id');
        $workspaceAppId = $this->rowInteger($workspace, 'app_id');
        $instanceAppId = DB::table('instances')->where('id', $instanceId)->value('app_id');

        if (! is_int($instanceAppId) && ! is_numeric($instanceAppId)) {
            throw $this->ownershipException($route, "identifies missing instance_id={$instanceId}");
        }

        if ((int) $instanceAppId !== $workspaceAppId) {
            throw $this->ownershipException(
                $route,
                "workspace_id={$workspaceId} app_id={$workspaceAppId} conflicts with instance_id={$instanceId} app_id={$instanceAppId}",
            );
        }
    }

    private function configuredInstanceId(object $route, bool $requirePositiveEvidence): int
    {
        $appId = $this->rowNullableInteger($route, 'app_id');

        if ($appId === null) {
            throw $this->ownershipException($route, 'has no app_id');
        }

        $app = DB::table('apps')->where('id', $appId)->first();

        if (! is_object($app)) {
            throw $this->ownershipException($route, "identifies missing app_id={$appId}");
        }

        $instances = DB::table('instances')
            ->where('app_id', $appId)
            ->orderBy('id')
            ->get();

        if ($instances->isEmpty()) {
            throw $this->ownershipException($route, "has no Instance candidates for app_id={$appId}");
        }

        $config = $this->routeConfig($route);
        $instanceConfig = is_array($config['instance'] ?? null) ? $config['instance'] : [];
        $targetConfig = is_array($config['target'] ?? null) ? $config['target'] : [];
        $identities = [];

        foreach ([
            'instance_id' => $this->rowNullableInteger($route, 'instance_id'),
            'config.instance_id' => $this->configuredOwnershipId(
                $route,
                $config,
                'instance_id',
                'config.instance_id',
            ),
            'instance.id' => $this->configuredOwnershipId($route, $instanceConfig, 'id', 'instance.id'),
        ] as $label => $instanceId) {
            if ($instanceId === null) {
                continue;
            }

            $instance = $instances->first(
                fn (object $candidate): bool => $this->rowInteger($candidate, 'id') === $instanceId,
            );

            if (! is_object($instance)) {
                throw $this->ownershipException($route, "identifies missing instance_id={$instanceId}");
            }

            $identities[$label] = $instanceId;
        }

        foreach ([
            'instance.name' => $this->nullableString($instanceConfig['name'] ?? null),
            'instance.selector' => $this->nullableString($instanceConfig['selector'] ?? null),
            'instance.domain' => $this->nullableString($instanceConfig['domain'] ?? null),
            'target.value' => ($targetConfig['type'] ?? null) === 'instance'
                ? $this->nullableString($targetConfig['value'] ?? null)
                : null,
        ] as $label => $value) {
            if ($value === null) {
                continue;
            }

            $matches = $instances
                ->filter(fn (object $instance): bool => $this->instanceMatches(
                    $instance,
                    $this->rowString($app, 'name'),
                    $value,
                ))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            if ($matches === []) {
                throw $this->ownershipException($route, "configured identity {$label}='{$value}' matches no Instance");
            }

            if (count($matches) > 1) {
                throw $this->ownershipException(
                    $route,
                    "configured identity {$label}='{$value}' is ambiguous across candidates ["
                    .implode(', ', $matches)
                    .']',
                );
            }

            $identities[$label] = $matches[0];
        }

        $domain = $this->rowString($route, 'domain');
        $domainMatches = $instances
            ->filter(fn (object $instance): bool => $this->instanceDomain($instance) === mb_strtolower($domain))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if (count($domainMatches) > 1) {
            throw $this->ownershipException(
                $route,
                'has ambiguous domain ownership candidates ['.implode(', ', $domainMatches).']',
            );
        }

        if (count($domainMatches) === 1) {
            $identities['route.domain'] = $domainMatches[0];
        }

        if ($identities !== []) {
            $instanceIds = array_values(array_unique($identities));

            if (count($instanceIds) !== 1) {
                $details = collect($identities)
                    ->map(static fn (int $instanceId, string $label): string => "{$label}=>{$instanceId}")
                    ->implode(', ');

                throw $this->ownershipException($route, "has competing instance identities: {$details}");
            }

            return $instanceIds[0];
        }

        if ($requirePositiveEvidence) {
            throw $this->ownershipException(
                $route,
                'has no positive legacy evidence for an existing Instance owner',
            );
        }

        if ($instances->count() === 1) {
            $instance = $instances->first();

            if (! is_object($instance)) {
                throw $this->ownershipException($route, 'has no readable Instance owner');
            }

            return $this->rowInteger($instance, 'id');
        }

        $candidateIds = $instances
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        throw $this->ownershipException(
            $route,
            'has no provably unique owner among Instance candidates ['.implode(', ', $candidateIds).']',
        );
    }

    private function assertConfiguredIdsMatch(object $route, int $instanceId): void
    {
        $persistedInstanceId = $this->rowNullableInteger($route, 'instance_id');

        if ($persistedInstanceId !== null && $persistedInstanceId !== $instanceId) {
            throw $this->ownershipException(
                $route,
                "workspace owner instance_id={$instanceId} conflicts with persisted instance_id={$persistedInstanceId}",
            );
        }

        $config = $this->routeConfig($route);
        $instanceConfig = is_array($config['instance'] ?? null) ? $config['instance'] : [];

        foreach ([
            $this->configuredOwnershipId($route, $config, 'instance_id', 'config.instance_id'),
            $this->configuredOwnershipId($route, $instanceConfig, 'id', 'instance.id'),
        ] as $configuredId) {
            if ($configuredId !== null && $configuredId !== $instanceId) {
                throw $this->ownershipException(
                    $route,
                    "workspace owner instance_id={$instanceId} conflicts with configured instance_id={$configuredId}",
                );
            }
        }
    }

    private function assertAppIdentityMatches(object $route, int $instanceId): void
    {
        $routeAppId = $this->rowNullableInteger($route, 'app_id');

        if ($routeAppId === null) {
            throw $this->ownershipException($route, 'has no app_id');
        }

        $instanceAppId = DB::table('instances')->where('id', $instanceId)->value('app_id');

        if (! is_int($instanceAppId) && ! is_numeric($instanceAppId)) {
            throw $this->ownershipException($route, "identifies missing instance_id={$instanceId}");
        }

        if ((int) $instanceAppId !== $routeAppId) {
            throw $this->ownershipException(
                $route,
                "app_id={$routeAppId} conflicts with instance_id={$instanceId} app_id={$instanceAppId}",
            );
        }
    }

    private function instanceMatches(object $instance, string $appName, string $value): bool
    {
        $value = mb_strtolower(trim($value));
        $name = mb_strtolower($this->rowString($instance, 'name'));

        return in_array(
            $value,
            [
                $name,
                mb_strtolower("{$appName}.{$name}"),
                $this->instanceDomain($instance),
            ],
            true,
        );
    }

    private function instanceDomain(object $instance): string
    {
        $config = $this->instanceDriverConfig($instance);
        $data = is_array($config['data'] ?? null) ? $config['data'] : $config;
        $domain = $this->nullableString($data['domain'] ?? null);

        return $domain === null ? '' : mb_strtolower($domain);
    }

    private function ownershipException(object $route, string $reason): RuntimeException
    {
        return new RuntimeException(sprintf(
            "ProxyRoute instance ownership migration blocked by proxy_routes#%d domain='%s' owner_type='%s' %s. Repair or remove this legacy route, then rerun migrations.",
            $this->rowInteger($route, 'id'),
            $this->rowString($route, 'domain'),
            $this->rowString($route, 'owner_type'),
            $reason,
        ));
    }

    private function assertLegacyJsonIsValid(): void
    {
        foreach (DB::table('proxy_routes')->orderBy('id')->get() as $route) {
            $this->routeConfig($route);
        }

        foreach (DB::table('instances')->orderBy('id')->get() as $instance) {
            $this->instanceDriverConfig($instance);
        }
    }

    /** @return array<string, mixed> */
    private function routeConfig(object $route): array
    {
        try {
            return $this->decodeJson($route->config ?? null);
        } catch (\JsonException) {
            throw $this->ownershipException($route, 'has malformed config JSON');
        }
    }

    /** @return array<string, mixed> */
    private function instanceDriverConfig(object $instance): array
    {
        try {
            return $this->decodeJson($instance->driver_config ?? null);
        } catch (\JsonException) {
            throw new RuntimeException(sprintf(
                'ProxyRoute instance ownership migration blocked by instances#%d has malformed driver_config JSON. Repair or remove this legacy Instance, then rerun migrations.',
                $this->rowInteger($instance, 'id'),
            ));
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        $decoded = $value;

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
        }

        if (! is_array($decoded)) {
            return [];
        }

        $result = [];

        foreach ($decoded as $key => $entry) {
            if (is_string($key)) {
                $result[$key] = $entry;
            }
        }

        return $result;
    }

    private function rowInteger(object $row, string $key): int
    {
        $value = $row->{$key} ?? null;

        if (! is_int($value) && ! is_numeric($value)) {
            throw new RuntimeException("ProxyRoute instance ownership migration expected integer {$key}.");
        }

        return (int) $value;
    }

    private function rowNullableInteger(object $row, string $key): ?int
    {
        $value = $row->{$key} ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("ProxyRoute instance ownership migration expected positive integer {$key}.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $config */
    private function configuredOwnershipId(object $route, array $config, string $key, string $path): ?int
    {
        if (! array_key_exists($key, $config) || $config[$key] === null) {
            return null;
        }

        $value = $config[$key];

        if (! is_int($value) || $value < 1) {
            throw $this->ownershipException(
                $route,
                "configured ownership hint {$path} must be a positive integer",
            );
        }

        return $value;
    }

    private function rowString(object $row, string $key): string
    {
        $value = $row->{$key} ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("ProxyRoute instance ownership migration expected string {$key}.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
};
