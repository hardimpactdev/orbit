<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Models\AppInstance;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class AppShowPlacementPayload
{
    public function __construct(
        private AppInstancePayloads $instancePayloads,
        private WorkspacePlacement $workspacePlacement,
        private WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    /**
     * @param  list<AppInstance>  $instances
     * @return array{
     *     instances: list<array<string, mixed>>,
     * }
     */
    public function forApp(App $app, array $instances, bool $includeWorkspaces = true): array
    {
        $visibleInstanceIds = array_map(static fn (AppInstance $instance): int => $instance->id, $instances);
        $workspacePayloads = $includeWorkspaces
            ? $this->workspacePayloadsByInstance($visibleInstanceIds)
            : [];
        $instancePayloads = [];

        foreach ($instances as $instance) {
            $instancePayloads[] = [
                ...$this->instancePayloads->placement($instance),
                'workspaces' => array_map(static fn (array $workspace): array => [
                    'name' => $workspace['name'],
                    'url' => $workspace['url'],
                    'lifecycle_status' => $workspace['lifecycle_status'],
                ], $workspacePayloads[$instance->id] ?? []),
            ];
        }

        return [
            'instances' => $instancePayloads,
        ];
    }

    /**
     * @param  list<int>  $visibleInstanceIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function workspacePayloadsByInstance(array $visibleInstanceIds): array
    {
        $payloads = [];
        $workspaces = Workspace::query()
            ->with('appInstance')
            ->whereIn('app_instance_id', $visibleInstanceIds)
            ->orderBy('name')
            ->get();

        foreach ($workspaces as $workspace) {
            if (
                ! $this->workspaceRoleGuard->nodeSupportsWorkspaces(
                    $this->workspacePlacement->nodeForWorkspace($workspace),
                )
            ) {
                continue;
            }

            $payloads[$workspace->app_instance_id][] = [
                'name' => $workspace->name,
                'url' => $workspace->url(),
                'lifecycle_status' => $workspace->lifecycle_status->value,
            ];
        }

        return $payloads;
    }
}
