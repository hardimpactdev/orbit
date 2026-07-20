<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\AppInstance;
use App\Models\Node;
use App\Services\AgentIde\AgentIdeAdapterRegistry;
use App\Services\Nodes\NodeAgentIdeDefaults;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppAgentIdeDefaults
{
    public function __construct(
        private AgentIdeAdapterRegistry $registry,
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return array{
     *     instance: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }
     */
    public function set(AppInstance $instance, string $adapter): array
    {
        $instance->loadMissing('project.node');

        $previousAdapter = $this->payloadFor($instance)['effective_adapter'];
        $currentAdapter = $this->explicitAdapter($instance);
        $normalizedAdapter = $adapter === 'inherit' ? null : $adapter;
        $action = $currentAdapter === $normalizedAdapter ? 'converged' : 'set';

        if ($action === 'set') {
            $config = is_array($instance->agent_ide_config) ? $instance->agent_ide_config : [];

            if ($normalizedAdapter === null) {
                unset($config['adapter']);
            } else {
                $config['adapter'] = $normalizedAdapter;
            }

            $instance->agent_ide_config = $config === [] ? null : $config;
            $instance->save();
            $instance->refresh();
            $instance->loadMissing('project.node');
        }

        return [
            'instance' => $this->instancePayload($instance),
            'agent_ide' => $this->payloadFor($instance),
            'cleanup' => ['workspaces_removed' => []],
            'action' => $action,
            'previous_adapter' => $previousAdapter,
        ];
    }

    public function isSupported(string $adapter): bool
    {
        return in_array($adapter, $this->supportedAdapters(), true);
    }

    /**
     * @return list<string>
     */
    public function supportedAdapters(): array
    {
        return $this->registry->supportedInputsForScope('instance');
    }

    /**
     * @return array{adapter: string|null, source: string, effective_adapter: string|null}
     */
    public function payloadFor(AppInstance $instance, ?Node $node = null): array
    {
        $explicitAdapter = $this->explicitAdapter($instance);

        if ($explicitAdapter === 'none') {
            return [
                'adapter' => 'none',
                'source' => 'instance',
                'effective_adapter' => null,
            ];
        }

        if ($explicitAdapter !== null) {
            return [
                'adapter' => $explicitAdapter,
                'source' => 'instance',
                'effective_adapter' => $explicitAdapter,
            ];
        }

        $instance->loadMissing('project.node');
        $node ??= $this->placement->nodeForInstance($instance);

        if ($node instanceof Node) {
            $nodeDefault = NodeAgentIdeDefaults::payloadFor($node);
            $nodeAdapter = $nodeDefault['adapter'];

            if ($nodeAdapter !== null) {
                return [
                    'adapter' => null,
                    'source' => 'node',
                    'effective_adapter' => $nodeAdapter,
                ];
            }
        }

        return [
            'adapter' => null,
            'source' => 'default',
            'effective_adapter' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function instancePayload(AppInstance $instance): array
    {
        $instance->loadMissing('project.node');
        $project = $instance->project;

        return [
            'project' => $project->name,
            'name' => $instance->name,
            'node' => $this->placement->nodeForInstance($instance)?->name,
        ];
    }

    private function explicitAdapter(AppInstance $instance): ?string
    {
        $adapter = $instance->agent_ide_config['adapter'] ?? null;

        return is_string($adapter) && $adapter !== '' ? $adapter : null;
    }
}
