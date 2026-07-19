<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\AppWorkerReadinessResult;
use App\Data\Apps\PhpWorkerConfig;
use App\Models\App;
use App\Models\AppInstance;

final readonly class AppWorkerService
{
    public function __construct(
        private AppWorkerReadiness $readiness,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     instance: AppInstance,
     *     readiness: AppWorkerReadinessResult,
     *     changed: bool,
     * }
     */
    public function enable(App $app, AppInstance $instance): array
    {
        $readiness = $this->readiness->assess($app, $instance);

        if (! $readiness->ready) {
            return [
                'ready' => false,
                'instance' => $instance,
                'readiness' => $readiness,
                'changed' => false,
            ];
        }

        $changed = ! $instance->worker_enabled || ! is_array($instance->worker_config);
        $existing = is_array($instance->worker_config) ? $instance->worker_config : [];
        $config = PhpWorkerConfig::fromArray($existing)->toArray();

        $instance->worker_enabled = true;
        $instance->worker_config = $config;
        $instance->save();

        return [
            'ready' => true,
            'instance' => $instance,
            'readiness' => $readiness,
            'changed' => $changed || $existing !== $config,
        ];
    }

    /**
     * @return array{instance: AppInstance, changed: bool}
     */
    public function disable(AppInstance $instance): array
    {
        $changed = $instance->worker_enabled === true;

        $instance->worker_enabled = false;
        // Keep worker_config so subsequent enables remember the prior config.
        $instance->save();

        return [
            'instance' => $instance,
            'changed' => $changed,
        ];
    }
}
