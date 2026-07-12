<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\AppInstance;
use App\Models\AppInstanceRuntimeMount;
use App\Services\Php\PhpRuntimeCatalog;
use InvalidArgumentException;

final readonly class AppInstancePayloads
{
    public function __construct(
        private PhpRuntimeCatalog $phpRuntimeCatalog = new PhpRuntimeCatalog,
        private LaravelCloudRuntimeCompatibility $cloudCompatibility = new LaravelCloudRuntimeCompatibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function instance(AppInstance $instance): array
    {
        $instance->loadMissing(['app.node', 'runtimeMounts']);

        return [
            'app' => $instance->app->name,
            'name' => $instance->name,
            'driver' => $instance->driver->value,
            'driver_config' => $instance->driver_config?->toArray() ?? [],
            'runtime' => $this->runtime($instance),
            'deploy_warmup_paths' => $instance->deploy_warmup_paths ?? [],
            'latest_deployment_status' => $instance->latest_deployment_status,
            'latest_deployment_run_id' => $instance->latest_deployment_run_id,
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
        $app = $instance->app;
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
            'mode' => $app->worker_enabled ? 'worker' : 'classic',
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
