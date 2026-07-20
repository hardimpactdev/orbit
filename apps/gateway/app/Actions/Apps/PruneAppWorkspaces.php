<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Actions\Workspaces\RemoveWorkspace;
use App\Contracts\AgentIdeMessageAdapter;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Apps\AppAgentIdeDefaults;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use RuntimeException;

final readonly class PruneAppWorkspaces
{
    public function __construct(
        private RemoveWorkspace $removeWorkspace,
        private AppAgentIdeDefaults $agentIdeDefaults,
        private AgentIdeMessageAdapter $adapter,
        private WorkspaceRoleGuard $workspaceRoleGuard,
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return array{
     *     project: string,
     *     instance: string,
     *     stale_workspaces: list<array{name: string, removed: bool}>,
     *     warnings: list<array<string, string>>,
     *     dry_run: bool,
     * }
     */
    public function handle(
        Project $app,
        AppInstance $instance,
        bool $dryRun = false,
        ?string $adapterName = null,
    ): array {
        $node = $this->placement->nodeForInstance($instance);
        $this->workspaceRoleGuard->ensureNodeSupportsWorkspaces($app, $node);

        $effectiveAdapter = $adapterName ?? $this->agentIdeDefaults->payloadFor($instance, $node)['effective_adapter'];

        if ($effectiveAdapter === null) {
            throw new RuntimeException('No agent IDE adapter configured for this instance.');
        }

        $nodeName = $node instanceof Node ? $node->name : '';

        $adapterWorkspaces = $this->adapter->workspaces(
            ['app' => $app->name, 'instance' => $instance->name, 'node' => $nodeName],
            $effectiveAdapter,
        );

        $trackedWorkspaces = Workspace::query()
            ->where('app_id', $app->id)
            ->where('app_instance_id', $instance->id)
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        /** @var list<string> $trackedWorkspaces */
        $staleWorkspaces = array_values(array_diff($trackedWorkspaces, $adapterWorkspaces));

        if ($dryRun) {
            return [
                'project' => $app->name,
                'instance' => $instance->name,
                'stale_workspaces' => array_map(fn (string $name): array => [
                    'name' => $name,
                    'removed' => false,
                ], $staleWorkspaces),
                'warnings' => [],
                'dry_run' => true,
            ];
        }

        /** @var list<array{name: string, removed: bool}> $results */
        $results = [];
        /** @var list<array<string, string>> $warnings */
        $warnings = [];

        foreach ($staleWorkspaces as $workspaceName) {
            $workspace = Workspace::query()
                ->where('app_id', $app->id)
                ->where('app_instance_id', $instance->id)
                ->where('name', $workspaceName)
                ->first();

            if (! $workspace instanceof Workspace) {
                continue;
            }

            try {
                $result = $this->removeWorkspace->handle($workspace, keepFiles: false);

                if (! empty($result['warnings'])) {
                    $warnings = array_merge($warnings, $result['warnings']);
                }

                $results[] = [
                    'name' => $workspaceName,
                    'removed' => true,
                ];
            } catch (RuntimeException $e) {
                $warnings[] = [
                    'code' => 'workspace.remove_failed',
                    'family' => 'workspace',
                    'message' => "Failed to remove workspace '{$workspaceName}': {$e->getMessage()}",
                    'next_command' => "workspace:remove {$workspaceName} --instance={$app->name}.{$instance->name} --force",
                ];

                $results[] = [
                    'name' => $workspaceName,
                    'removed' => false,
                ];
            }
        }

        return [
            'project' => $app->name,
            'instance' => $instance->name,
            'stale_workspaces' => $results,
            'warnings' => $warnings,
            'dry_run' => false,
        ];
    }
}
