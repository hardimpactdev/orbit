<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\AppInstance;
use App\Models\Project;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;

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
    public function forApp(Project $app, array $instances, bool $includeWorkspaces = true): array
    {
        $visibleInstanceIds = array_map(static fn (AppInstance $instance): int => $instance->id, $instances);
        $workspacePayloads = $includeWorkspaces
            ? $this->workspacePayloadsByInstance($app, $visibleInstanceIds)
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
     * @return array<int, list<array{name: string, url: string, lifecycle_status: string}>>
     */
    private function workspacePayloadsByInstance(Project $app, array $visibleInstanceIds): array
    {
        $payloads = [];
        $app->loadMissing('workspaces');

        foreach ($app->workspaces as $workspace) {
            if (! in_array($workspace->app_instance_id, $visibleInstanceIds, strict: true)) {
                continue;
            }

            if (
                ! $this->workspaceRoleGuard->nodeSupportsWorkspaces(
                    $this->workspacePlacement->nodeForWorkspace($workspace),
                )
            ) {
                continue;
            }

            $payloads[(int) $workspace->app_instance_id][] = [
                'name' => $workspace->name,
                'url' => $workspace->url(),
                'lifecycle_status' => $workspace->lifecycle_status->value,
            ];
        }

        return $payloads;
    }
}
