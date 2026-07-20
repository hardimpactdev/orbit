<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\LaravelCloudAppInstanceDriverConfigData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\AppInstance;
use App\Models\AppInstanceRuntimeMount;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Workspaces\WorkspacePlacement;
use InvalidArgumentException;

final readonly class AppInstancePayloads
{
    public function __construct(
        private WorkspacePlacement $workspacePlacement,
        private PhpRuntimeCatalog $phpRuntimeCatalog = new PhpRuntimeCatalog,
        private LaravelCloudRuntimeCompatibility $cloudCompatibility = new LaravelCloudRuntimeCompatibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function instance(AppInstance $instance): array
    {
        $instance->loadMissing(['project.node', 'runtimeMounts']);

        return [
            'project' => $instance->project->name,
            ...$this->placement($instance),
            'driver_config' => $instance->driver_config?->toArray() ?? [],
            'runtime' => $this->runtime($instance),
            'worker_enabled' => $instance->worker_enabled,
            'worker_config' => is_array($instance->worker_config) ? $instance->worker_config : null,
            'deploy_warmup_paths' => $instance->deploy_warmup_paths ?? [],
            'latest_deployment_status' => $instance->latest_deployment_status,
            'latest_deployment_run_id' => $instance->latest_deployment_run_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function placement(AppInstance $instance): array
    {
        $instance->loadMissing('project');
        $config = $instance->driver_config;
        $domain = match (true) {
            $config instanceof OrbitAppInstanceDriverConfigData => $config->domain,
            $config instanceof LaravelCloudAppInstanceDriverConfigData => $config->domain,
            default => null,
        };
        $host = $config instanceof LaravelCloudAppInstanceDriverConfigData
            ? $domain
            : $this->workspacePlacement->instanceUrlHost($instance, $instance->project);

        return [
            'name' => $instance->name,
            'driver' => $instance->driver->value,
            'environment' => $config instanceof LaravelCloudAppInstanceDriverConfigData
                ? $config->environment_name
                : $instance->name,
            'node' => $config instanceof OrbitAppInstanceDriverConfigData
                ? $config->node ?? $this->workspacePlacement->nodeForInstance($instance)?->name
                : null,
            'url' => is_string($host) && $host !== '' ? "https://{$host}" : null,
            'path' => $config instanceof OrbitAppInstanceDriverConfigData ? $config->path : null,
            'root' => $config instanceof OrbitAppInstanceDriverConfigData ? $config->document_root : null,
            'domain' => $domain,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function withCompatibility(AppInstance $instance): array
    {
        return [
            'instance' => $this->instance($instance),
            'cloud_compatibility' => $this->cloudCompatibility->forInstance($instance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runtime(AppInstance $instance): array
    {
        $app = $instance->project;
        $image = null;
        $runtime = $app->runtimeKind();

        try {
            if ($runtime === AppRuntimeKind::Php) {
                $image = $this->phpRuntimeCatalog->imageFor($app->php_version);
            }
        } catch (InvalidArgumentException) {
            $image = null;
        }

        return [
            'runtime' => $runtime->value,
            'runtime_config' => $app->runtimeConfig()->toArray(),
            'php_version' => $app->php_version,
            'frankenphp_image' => $image,
            'mode' => $instance->worker_enabled ? 'worker' : 'classic',
            'configured_mounts' => $instance
                ->runtimeMounts
                ->map(fn (AppInstanceRuntimeMount $mount): array => [
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
