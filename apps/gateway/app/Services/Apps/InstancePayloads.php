<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\LaravelCloudInstanceDriverConfigData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\Instance;
use App\Models\InstanceRuntimeMount;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Workspaces\WorkspacePlacement;
use InvalidArgumentException;

final readonly class InstancePayloads
{
    public function __construct(
        private WorkspacePlacement $workspacePlacement,
        private PhpRuntimeCatalog $phpRuntimeCatalog = new PhpRuntimeCatalog,
        private LaravelCloudRuntimeCompatibility $cloudCompatibility = new LaravelCloudRuntimeCompatibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function instance(Instance $instance): array
    {
        $instance->loadMissing(['app', 'runtimeMounts', 'latestDeploymentRun']);
        $latestDeploymentRun = $instance->latestDeploymentRun;

        return [
            'app' => $instance->app->name,
            ...$this->placement($instance),
            'driver_config' => $instance->driver_config?->toArray() ?? [],
            'runtime' => $this->runtime($instance),
            'worker_enabled' => $instance->worker_enabled,
            'worker_config' => is_array($instance->worker_config) ? $instance->worker_config : null,
            'deploy_warmup_paths' => $instance->deploy_warmup_paths ?? [],
            'latest_deployment_status' => $latestDeploymentRun?->status,
            'latest_deployment_run_id' => $latestDeploymentRun?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function placement(Instance $instance): array
    {
        $instance->loadMissing('app');
        $config = $instance->driver_config;
        $domain = match (true) {
            $config instanceof OrbitInstanceDriverConfigData => $config->domain,
            $config instanceof LaravelCloudInstanceDriverConfigData => $config->domain,
            default => null,
        };
        $host = $config instanceof LaravelCloudInstanceDriverConfigData
            ? $domain
            : $this->workspacePlacement->instanceUrlHost($instance, $instance->app);

        return [
            'name' => $instance->name,
            'driver' => $instance->driver->value,
            'environment' => $config instanceof LaravelCloudInstanceDriverConfigData
                ? $config->environment_name
                : $instance->name,
            'node' => $config instanceof OrbitInstanceDriverConfigData
                ? $config->node ?? $this->workspacePlacement->nodeForInstance($instance)?->name
                : null,
            'url' => is_string($host) && $host !== '' ? "https://{$host}" : null,
            'path' => $config instanceof OrbitInstanceDriverConfigData ? $config->path : null,
            'root' => $config instanceof OrbitInstanceDriverConfigData ? $config->document_root : null,
            'domain' => $domain,
            'adopted' => $instance->adopted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function withCompatibility(Instance $instance): array
    {
        return [
            'instance' => $this->instance($instance),
            'cloud_compatibility' => $this->cloudCompatibility->forInstance($instance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runtime(Instance $instance): array
    {
        $app = $instance->app;
        $image = null;
        $runtime = $app->runtimeKind();
        // The instance owns its version; the app value is only the creation
        // template, so reporting it here would misstate what actually runs.
        $phpVersion = $instance->php_version ?? $app->php_version;

        try {
            if ($runtime === AppRuntimeKind::Php) {
                $image = $this->phpRuntimeCatalog->imageFor($phpVersion);
            }
        } catch (InvalidArgumentException) {
            $image = null;
        }

        return [
            'runtime' => $runtime->value,
            'runtime_config' => $app->runtimeConfig()->toArray(),
            'php_version' => $phpVersion,
            'frankenphp_image' => $image,
            'mode' => $instance->worker_enabled ? 'worker' : 'classic',
            'configured_mounts' => $instance
                ->runtimeMounts
                ->map(static fn (InstanceRuntimeMount $mount): array => [
                    'source' => $mount->source,
                    'target' => $mount->target,
                    'read_only' => $mount->read_only,
                ])
                ->values()
                ->all(),
            'required_php_extensions' => $instance->runtimeRequirements()->normalizedPhpExtensions(),
        ];
    }
}
