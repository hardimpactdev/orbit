<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Models\Node;

final readonly class ToolLogReader
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function read(string $tool, ?string $node = null, ?string $app = null, int $lines = 100): array|ToolRegistryFailure
    {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'logs');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $command = $this->catalog->logCommand($model->name, $lines);

        if ($command === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'logs');
        }

        $model->loadMissing('node');

        if (! $model->node instanceof Node) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'logs', 1, 'Target node is missing.');
        }

        $result = $this->remoteShell->run($model->node, $command, ['throw' => false]);

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed($tool, $model->node->name, 'logs', $result->exitCode, trim($result->stderr));
        }

        return [
            'tool' => $model->name,
            'node' => $model->node->name,
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
