<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShellStream;
use App\Models\Node;
use App\Services\Processes\ProcessRuntimeDriverRegistry;

final readonly class ToolLogFollower
{
    public function __construct(
        private RemoteShellStream $stream,
        private ToolRelatedProcessResolver $relatedProcesses,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @param  callable(string): void  $onOutput
     */
    public function follow(string $tool, ?string $node, ?string $app, ?string $instance, int $lines, callable $onOutput): int|ToolRegistryFailure
    {
        $target = $this->streamTarget($tool, $node, $app, $instance, $lines);

        if ($target instanceof ToolRegistryFailure) {
            return $target;
        }

        return $this->followTarget($tool, $target['node'], $target['command'], $onOutput);
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    public function followTarget(string $tool, Node $node, string $command, callable $onOutput): int|ToolRegistryFailure
    {
        $exitCode = $this->stream->stream($node, $command, $onOutput);

        if ($exitCode !== 0) {
            return ToolRegistryFailure::remoteActionFailed($tool, $node->name, 'logs', $exitCode, '');
        }

        return 0;
    }

    /**
     * @return array{node: Node, command: string}|ToolRegistryFailure
     */
    public function streamTarget(string $tool, ?string $node, ?string $app, ?string $instance, int $lines): array|ToolRegistryFailure
    {
        $target = $this->relatedProcesses->resolve($tool, $node, $app, 'logs', $instance);

        if ($target instanceof ToolRegistryFailure) {
            return $target;
        }

        $driver = $this->runtimeDrivers->forProcess($target->process);
        $runtimeUnit = $driver->runtimeUnitName($target->app, $target->process, $target->workspace);

        return [
            'node' => $target->node,
            'command' => $driver->logScript($target->app, $target->process, $target->workspace, $runtimeUnit, $lines, follow: true),
        ];
    }
}
