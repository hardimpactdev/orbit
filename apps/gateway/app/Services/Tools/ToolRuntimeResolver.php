<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;
use App\Models\Process;
use Illuminate\Database\Eloquent\Collection;

final readonly class ToolRuntimeResolver
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
    ) {}

    public function resolve(
        string $tool,
        string $action,
        ?string $node = null,
        ?string $app = null,
    ): ToolRuntimeTarget|ToolRegistryFailure {
        if (! $this->catalog->hasCapability($tool, $action)) {
            return ToolRegistryFailure::unsupportedAction($tool, $action);
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $model->loadMissing('node');

        if (! $model->node instanceof Node) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', $action, 1, 'Target node is missing.');
        }

        $processes = $this->processes($model->node, $tool);
        $directRuntime = $this->hasDirectRuntime($tool, $action, is_array($model->config) ? $model->config : []);
        $runtimeCount = $processes->count() + ($directRuntime ? 1 : 0);

        if ($runtimeCount === 0) {
            return ToolRegistryFailure::runtimeMissing($tool, $model->node->name, $action);
        }

        if ($runtimeCount > 1) {
            $processNames = [];

            foreach ($processes as $process) {
                $processNames[] = $process->name;
            }

            sort($processNames);

            return ToolRegistryFailure::runtimeAmbiguous(
                tool: $tool,
                node: $model->node->name,
                action: $action,
                processes: $processNames,
                toolOwnedRuntime: $directRuntime,
            );
        }

        return new ToolRuntimeTarget(
            tool: $model,
            node: $model->node,
            process: $processes->first(),
        );
    }

    /**
     * @return Collection<int, Process>
     */
    private function processes(Node $node, string $tool): Collection
    {
        $models = Process::query()
            ->with('owner')
            ->where('node_id', $node->id)
            ->where('tool', $tool)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        /** @var list<Process> $items */
        $items = [];

        foreach ($models as $model) {
            if ($model instanceof Process) {
                $items[] = $model;
            }
        }

        $processes = new Collection($items);

        /** @var Collection<int, Process> $processes */
        return $processes;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function hasDirectRuntime(string $tool, string $action, array $config): bool
    {
        if ($action === 'logs') {
            return $this->catalog->logCommand($tool, 1) !== null;
        }

        return $this->catalog->lifecycleScript($tool, $action, $config) !== null;
    }
}
