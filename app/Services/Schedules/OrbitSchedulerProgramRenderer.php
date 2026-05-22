<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Models\Node;
use App\Services\Runtime\OrbitContainerNames;

final readonly class OrbitSchedulerProgramRenderer
{
    public function __construct(
        private OrbitContainerNames $containerNames = new OrbitContainerNames,
    ) {}

    public function render(Node $node, ?int $sleepSeconds = null): string
    {
        return $this->definition($node, $sleepSeconds)['command'];
    }

    public function installScript(Node $node, ?int $sleepSeconds = null): string
    {
        $definition = $this->definition($node, $sleepSeconds);
        $container = $definition['container'];
        $command = $definition['command'];

        return implode("\n", [
            'set -e',
            'sudo docker inspect '.escapeshellarg($container).' >/dev/null',
            'sudo docker restart '.escapeshellarg($container).' >/dev/null',
            'sleep 1',
            "if ! sudo docker exec {$this->escapedContainer()} sh -lc ".escapeshellarg($this->schedulerRunningScript()).'; then',
            "    sudo docker exec --detach {$this->escapedContainer()} sh -lc ".escapeshellarg("exec {$command}").' >/dev/null',
            'fi',
        ]);
    }

    /**
     * @return array{
     *     container: string,
     *     command: string,
     *     restart_policy: string
     * }
     */
    public function definition(Node $node, ?int $sleepSeconds = null): array
    {
        $command = 'orbit orbit-scheduler';

        if ($sleepSeconds !== null) {
            $command .= " --sleep-seconds={$sleepSeconds}";
        }

        return [
            'container' => $this->containerNames->runtime(),
            'command' => $command,
            'restart_policy' => 'unless-stopped',
        ];
    }

    private function escapedContainer(): string
    {
        return escapeshellarg($this->containerNames->runtime());
    }

    private function schedulerRunningScript(): string
    {
        return "ps -eo args | grep -F 'artisan orbit-scheduler' | grep -v grep >/dev/null";
    }
}
