<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

final readonly class ToolRelatedProcessResolver
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
    ) {}

    public function resolve(string $tool, ?string $node, ?string $app, string $action, ?string $instance = null): ToolRelatedProcessTarget|ToolRegistryFailure
    {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, $action);
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app, instance: $instance);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $model->loadMissing('node');

        if (! $model->node instanceof Node) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', $action, 1, 'Target node is missing.');
        }

        $processes = $this->relatedProcesses($model->node, $tool);

        if ($processes->isEmpty()) {
            return ToolRegistryFailure::processMissing($tool, $model->node->name, $action);
        }

        if ($processes->count() > 1) {
            return ToolRegistryFailure::processAmbiguous(
                tool: $tool,
                node: $model->node->name,
                action: $action,
                processes: $processes
                    ->map(fn (Process $process): string => $process->name)
                    ->sort()
                    ->values()
                    ->all(),
            );
        }

        $process = $processes->first();

        $context = $this->contextFor($process, $model->node, $tool, $action);

        if ($context instanceof ToolRegistryFailure) {
            return $context;
        }

        return new ToolRelatedProcessTarget(
            tool: $model,
            node: $model->node,
            process: $process,
            app: $context['app'],
            workspace: $context['workspace'],
        );
    }

    /**
     * @return Collection<int, Process>
     */
    private function relatedProcesses(Node $node, string $tool): Collection
    {
        return Process::query()
            ->with('owner')
            ->where('node_id', $node->id)
            ->whereIn('tool', $this->processToolNames($tool))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function processToolNames(string $tool): array
    {
        $names = [$tool];

        $aliases = [
            'opencode-server' => 'opencode',
            'polyscope-server' => 'polyscope',
        ];

        if (isset($aliases[$tool])) {
            $names[] = $aliases[$tool];
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array{app: App, workspace: Workspace|null}|ToolRegistryFailure
     */
    private function contextFor(Process $process, Node $node, string $tool, string $action): array|ToolRegistryFailure
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof App) {
            $process->owner->setRelation('node', $node);

            return [
                'app' => $process->owner,
                'workspace' => null,
            ];
        }

        if ($process->owner instanceof Workspace) {
            $process->owner->loadMissing('app');

            if (! $process->owner->app instanceof App) {
                return ToolRegistryFailure::remoteActionFailed($tool, $node->name, $action, 1, 'Process workspace app is missing.');
            }

            $process->owner->app->setRelation('node', $node);

            return [
                'app' => $process->owner->app,
                'workspace' => $process->owner,
            ];
        }

        if ($process->owner instanceof Node) {
            return [
                'app' => $this->syntheticNodeApp($node),
                'workspace' => null,
            ];
        }

        return ToolRegistryFailure::remoteActionFailed($tool, $node->name, $action, 1, 'Process owner is not lifecycle-addressable.');
    }

    private function syntheticNodeApp(Node $node): App
    {
        $home = ($node->user ?: 'orbit') === 'root'
            ? '/root'
            : '/home/'.($node->user ?: 'orbit');

        $app = new App([
            'name' => $node->name,
            'path' => $home,
            'node_id' => $node->id,
        ]);
        $app->setRelation('node', $node);

        return $app;
    }
}
