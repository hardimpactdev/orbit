<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\WorkspaceSourceDriver;
use App\Contracts\WorkspaceSourceDrivers;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Apps\AppAgentIdeDefaults;
use Illuminate\Contracts\Container\Container;

final readonly class WorkspaceSourceDriverResolver implements WorkspaceSourceDrivers
{
    public function __construct(
        private AppAgentIdeDefaults $agentIdeDefaults,
        private WorktreeWorkspaceDriver $worktreeDriver,
        private OpenCodeWorkspaceDriver $openCodeDriver,
        private Container $container,
    ) {}

    public function resolve(Project $app, AppInstance $instance): WorkspaceSourceDriver
    {
        return match ($this->effectiveAdapter($instance)) {
            'polyscope' => $this->container->make(PolyscopeWorkspaceDriver::class),
            'opencode' => $this->openCodeDriver,
            null => $this->worktreeDriver,
            default => throw new WorkspaceCreateFailed(
                'workspace.agent_ide_driver_missing',
                'The effective agent IDE adapter does not have a workspace source driver.',
                [
                    'adapter' => $this->effectiveAdapter($instance),
                    'project' => $app->name,
                    'instance' => $instance->name,
                ],
            ),
        };
    }

    public function effectiveAdapter(AppInstance $instance): ?string
    {
        return $this->agentIdeDefaults->payloadFor($instance)['effective_adapter'];
    }

    /**
     * @return array{label: string, done_label: string}
     */
    public function progressLabels(AppInstance $instance, Node $node): array
    {
        if ($this->effectiveAdapter($instance) === 'polyscope') {
            return [
                'label' => "Provision Polyscope workspace on {$node->name}",
                'done_label' => "Provisioned Polyscope workspace on {$node->name}",
            ];
        }

        if ($this->effectiveAdapter($instance) === 'opencode') {
            return [
                'label' => "Provision OpenCode workspace on {$node->name}",
                'done_label' => "Provisioned OpenCode workspace on {$node->name}",
            ];
        }

        return [
            'label' => "Provision worktree on {$node->name}",
            'done_label' => "Provisioned worktree on {$node->name}",
        ];
    }
}
