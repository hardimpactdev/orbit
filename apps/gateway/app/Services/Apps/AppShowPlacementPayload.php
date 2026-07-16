<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\LaravelCloudAppInstanceDriverConfigData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class AppShowPlacementPayload
{
    public function __construct(
        private WorkspacePlacement $workspacePlacement,
    ) {}

    /**
     * @param  list<AppInstance>  $instances
     * @return array{
     *     instances: list<array<string, mixed>>,
     *     workspaces: list<array<string, mixed>>,
     * }
     */
    public function forApp(App $app, array $instances): array
    {
        $visibleInstanceIds = array_map(static fn (AppInstance $instance): int => $instance->id, $instances);
        $workspacePayloads = $this->workspacePayloadsByInstance($app, $visibleInstanceIds);
        $instancePayloads = [];

        foreach ($instances as $instance) {
            $instancePayloads[] = [
                'name' => $instance->name,
                'driver' => $instance->driver->value,
                'node' => $this->instanceNodeName($instance),
                'url' => $this->instanceUrl($instance, $app),
                'workspaces' => array_map(static fn (array $workspace): array => [
                    'name' => $workspace['name'],
                    'url' => $workspace['url'],
                    'lifecycle_status' => $workspace['lifecycle_status'],
                ], $workspacePayloads[$instance->id] ?? []),
            ];
        }

        $flatWorkspacePayloads = [];

        foreach ($workspacePayloads as $instanceWorkspaces) {
            array_push($flatWorkspacePayloads, ...$instanceWorkspaces);
        }

        usort(
            $flatWorkspacePayloads,
            static fn (array $left, array $right): int => (
                [$left['app_instance'], $left['name']] <=> [$right['app_instance'], $right['name']]
            ),
        );

        return [
            'instances' => $instancePayloads,
            'workspaces' => $flatWorkspacePayloads,
        ];
    }

    /**
     * @param  list<int>  $visibleInstanceIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function workspacePayloadsByInstance(App $app, array $visibleInstanceIds): array
    {
        $app->loadMissing('workspaces.appInstance');
        $payloads = [];

        foreach ($app->workspaces->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE) as $workspace) {
            if (
                ! $workspace instanceof Workspace
                || ! in_array($workspace->app_instance_id, $visibleInstanceIds, strict: true)
            ) {
                continue;
            }

            $payloads[$workspace->app_instance_id][] = [
                'name' => $workspace->name,
                'app_instance' => $workspace->appInstance->name,
                'url' => $workspace->url(),
                'lifecycle_status' => $workspace->lifecycle_status->value,
            ];
        }

        return $payloads;
    }

    private function instanceNodeName(AppInstance $instance): ?string
    {
        $config = $instance->driver_config;

        if ($config instanceof OrbitAppInstanceDriverConfigData && is_string($config->node) && $config->node !== '') {
            return $config->node;
        }

        return $this->workspacePlacement->nodeForInstance($instance)?->name;
    }

    private function instanceUrl(AppInstance $instance, App $app): ?string
    {
        $config = $instance->driver_config;

        if ($config instanceof LaravelCloudAppInstanceDriverConfigData) {
            return is_string($config->domain) && $config->domain !== '' ? "https://{$config->domain}" : null;
        }

        if (! $config instanceof OrbitAppInstanceDriverConfigData) {
            return null;
        }

        $host = $this->workspacePlacement->instanceUrlHost($instance, $app);

        return $host === '' ? null : "https://{$host}";
    }
}
