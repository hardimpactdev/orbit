<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\Apps\AppSelection;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Schedule;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Orbit\Sdk\Laravel\GatewayApiException;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ScheduleAppInstanceResolver
{
    public function __construct(
        private AppSelectorResolver $appSelectorResolver,
        private WorkspacePlacement $placement,
        private NodeAccessAuthorizer $authorizer,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function resolve(string $selector, Node $caller, string $permission): AppSelection
    {
        try {
            $selection = $this->appSelectorResolver->resolveRequired($selector);
        } catch (AppSelectionResolutionFailed $exception) {
            throw new GatewayApiException(
                $exception->getMessage(),
                $exception->errorCode,
                $exception->meta,
            );
        }

        if ($selection->instance instanceof AppInstance) {
            return $this->authorizeExplicitSelection($selection, $caller, $permission);
        }

        $eligible = $this->eligibleInstances($selection->app);
        $visible = array_values(array_filter(
            $eligible,
            fn (AppInstance $instance): bool => $this->callerCanAccess($caller, $instance, $permission),
        ));
        $instance = $visible[0] ?? null;

        if (count($visible) === 1 && $instance instanceof AppInstance) {
            return new AppSelection(
                app: $selection->app,
                instance: $instance,
                selector: $selection->selector,
                instanceSelector: $instance->name,
            );
        }

        if ($visible === [] && $eligible !== []) {
            throw new GatewayApiException(
                'This node is not authorized to manage schedules for the selected project.',
                'authorization_failed',
                [
                    'reason' => 'missing_permission',
                    'missing_permission' => $permission,
                    'project' => $selection->app->name,
                ],
            );
        }

        throw new GatewayApiException(
            "Project '{$selection->app->name}' requires a concrete instance selector.",
            'validation_failed',
            [
                'field' => 'instance',
                'reason' => 'instance_required',
                'project' => $selection->app->name,
                'instances' => array_map(
                    static fn (AppInstance $instance): string => $instance->name,
                    $visible,
                ),
            ],
        );
    }

    /**
     * @return list<int>
     */
    public function visibleInstanceIds(Node $caller, string $permission): array
    {
        $instances = AppInstance::all()->sortBy('id');
        /** @var list<int> $instanceIds */
        $instanceIds = [];

        foreach ($instances as $instance) {
            if (! $instance instanceof AppInstance) {
                continue;
            }

            if ($this->isEligible($instance) && $this->callerCanAccess($caller, $instance, $permission)) {
                $instanceIds[] = $instance->id;
            }
        }

        return $instanceIds;
    }

    public function targetNode(Schedule|AppInstance $target): ?Node
    {
        $instance = $target instanceof Schedule ? $target->appInstance : $target;

        return $instance instanceof AppInstance ? $this->placement->nodeForInstance($instance) : null;
    }

    public function executionPath(Schedule $schedule): ?string
    {
        $schedule->loadMissing('appInstance');
        $config = $schedule->appInstance?->driver_config;

        return $config instanceof OrbitAppInstanceDriverConfigData && is_string($config->path) && $config->path !== ''
            ? $config->path
            : null;
    }

    private function authorizeExplicitSelection(
        AppSelection $selection,
        Node $caller,
        string $permission,
    ): AppSelection {
        $instance = $selection->instance;

        if (! $instance instanceof AppInstance) {
            throw new GatewayApiException(
                "Instance '{$selection->selector}' cannot run schedules.",
                'validation_failed',
                [
                    'field' => 'instance',
                    'reason' => 'instance_unavailable',
                    'project' => $selection->app->name,
                    'instance' => $instance?->name,
                ],
            );
        }

        $canAccess = $this->callerCanAccess($caller, $instance, $permission);

        if (! $canAccess && ! $this->nodeRoleAssignments->nodeIsGateway($caller)) {
            throw new GatewayApiException(
                "Instance '{$selection->selector}' not found.",
                'validation_failed',
                [
                    'field' => 'instance',
                    'project' => $selection->app->name,
                    'instance' => $selection->instanceSelector,
                ],
            );
        }

        if (! $this->isEligible($instance)) {
            throw new GatewayApiException(
                "Instance '{$selection->selector}' cannot run schedules.",
                'validation_failed',
                [
                    'field' => 'instance',
                    'reason' => 'instance_unavailable',
                    'project' => $selection->app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        if ($canAccess) {
            return $selection;
        }

        $node = $this->targetNode($instance);

        throw new GatewayApiException(
            'This node is not authorized to manage schedules for the selected instance.',
            'authorization_failed',
            [
                'reason' => 'missing_permission',
                'missing_permission' => $permission,
                'serving_node' => $node?->name,
            ],
        );
    }

    /**
     * @return list<AppInstance>
     */
    private function eligibleInstances(Project $app): array
    {
        $app->loadMissing('instances');

        $eligible = [];

        foreach ($app->instances as $instance) {
            if (! $this->isEligible($instance)) {
                continue;
            }

            $eligible[] = $instance;
        }

        return $eligible;
    }

    private function isEligible(AppInstance $instance): bool
    {
        $node = $this->targetNode($instance);

        if (! $node instanceof Node) {
            return false;
        }

        return $node->isActive() && $this->nodeRoleAssignments->nodeCanServeGatewayOrAppHostWorkloads($node);
    }

    private function callerCanAccess(Node $caller, AppInstance $instance, string $permission): bool
    {
        $node = $this->targetNode($instance);

        return $node instanceof Node && $this->authorizer->allows($caller, $node, $permission);
    }
}
