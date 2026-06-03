<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Services\Processes\ProcessRuntimeDriverRegistry;

final readonly class ToolLogReader
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ToolRelatedProcessResolver $relatedProcesses,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function read(string $tool, ?string $node = null, ?string $app = null, int $lines = 100, bool $follow = false): array|ToolRegistryFailure
    {
        $target = $this->relatedProcesses->resolve($tool, $node, $app, 'logs');

        if ($target instanceof ToolRegistryFailure) {
            return $target;
        }

        $driver = $this->runtimeDrivers->forProcess($target->process);
        $runtimeUnit = $driver->runtimeUnitName($target->app, $target->process, $target->workspace);
        $command = $driver->logScript($target->app, $target->process, $target->workspace, $runtimeUnit, $lines, $follow);
        $result = $this->remoteShell->run($target->node, $command, ['throw' => false]);

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed($tool, $target->node->name, 'logs', $result->exitCode, trim($result->stderr));
        }

        return [
            'tool' => $target->tool->name,
            'node' => $target->node->name,
            'process' => $target->process->name,
            'runtime_unit' => $runtimeUnit,
            'lines' => $this->lines($result->stdout),
        ];
    }

    /**
     * @return list<array{message: string}>
     */
    private function lines(string $stdout): array
    {
        $lines = preg_split('/\R/', trim($stdout));

        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_map(
            fn (string $line): array => ['message' => $line],
            array_filter($lines, fn (string $line): bool => $line !== ''),
        ));
    }
}
