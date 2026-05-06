<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShellStream;
use App\Models\Node;

final readonly class ToolLogFollower
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShellStream $stream,
    ) {}

    /**
     * @param  callable(string): void  $onOutput
     */
    public function follow(string $tool, ?string $node, ?string $app, int $lines, callable $onOutput): int|ToolRegistryFailure
    {
        $target = $this->streamTarget($tool, $node, $app, $lines);

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
    public function streamTarget(string $tool, ?string $node, ?string $app, int $lines): array|ToolRegistryFailure
    {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'logs');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $command = $this->catalog->logCommand($model->name, $lines, follow: true);

        if ($command === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'logs');
        }

        $model->loadMissing('node');

        if (! $model->node instanceof Node) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'logs', 1, 'Target node is missing.');
        }

        return [
            'node' => $model->node,
            'command' => $command,
        ];
    }
}
