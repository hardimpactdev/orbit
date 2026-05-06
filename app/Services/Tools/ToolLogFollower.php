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

        $exitCode = $this->stream->stream($model->node, $command, $onOutput);

        if ($exitCode !== 0) {
            return ToolRegistryFailure::remoteActionFailed($tool, $model->node->name, 'logs', $exitCode, '');
        }

        return 0;
    }
}
