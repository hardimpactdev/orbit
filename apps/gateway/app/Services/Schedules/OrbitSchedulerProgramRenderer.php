<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Services\Gateway\GatewaySwarmStackRenderer;

final class OrbitSchedulerProgramRenderer
{
    private const string Stack = 'orbit';

    public function render(?int $sleepSeconds = null): string
    {
        return $this->definition($sleepSeconds)['command'];
    }

    public function installScript(?int $sleepSeconds = null): string
    {
        $definition = $this->definition($sleepSeconds);
        $stackService = $definition['stack_service'];

        return implode("\n", [
            'set -e',
            'sudo docker service inspect '.escapeshellarg($stackService).' >/dev/null',
            'sudo docker service scale '.escapeshellarg("{$stackService}=1").' >/dev/null',
        ]);
    }

    /**
     * @return array{
     *     service: string,
     *     stack_service: string,
     *     command: string,
     *     replicas: int,
     *     update_order: string
     * }
     */
    public function definition(?int $sleepSeconds = null): array
    {
        $command = 'php artisan orbit-scheduler';

        if ($sleepSeconds !== null) {
            $command .= " --sleep-seconds={$sleepSeconds}";
        }

        return [
            'service' => GatewaySwarmStackRenderer::SchedulerService,
            'stack_service' => self::Stack.'_'.GatewaySwarmStackRenderer::SchedulerService,
            'command' => $command,
            'replicas' => 1,
            'update_order' => 'stop-first',
        ];
    }
}
