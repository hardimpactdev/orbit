<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\Apps\AppSelection;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Orbit\Sdk\Laravel\GatewayApiException;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ScheduleInstanceResolver
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

        if ($selection->instance instanceof Instance) {
            return $this->authorizeExplicitSelection($selection, $caller, $permission);
        }

        $eligible = $this->eligibleInstances($selection->app);
        $visible = array_values(array_filter(
            $eligible,
            fn (Instance $instance): bool => $this->callerCanAccess($caller, $instance, $permission),
        ));
        $instance = $visible[0] ?? null;

        if (count($visible) === 1 && $instance instanceof Instance) {
            return new AppSelection(
                app: $selection->app,
                instance: $instance,
                selector: $selection->selector,
                instanceSelector: $instance->name,
            );
        }

        if ($visible === [] && $eligible !== []) {
            throw new GatewayApiException(
                'This node is not authorized to manage schedules for the selected app.',
                'authorization_failed',
                [
                    'reason' => 'missing_permission',
                    'missing_permission' => $permission,
                    'app' => $selection->app->name,
                ],
            );
        }

        throw new GatewayApiException(
            "App '{$selection->app->name}' requires a concrete instance selector.",
            'validation_failed',
            [
                'field' => 'instance',
                'reason' => 'instance_required',
                'app' => $selection->app->name,
                'instances' => array_map(
                    static fn (Instance $instance): string => $instance->name,
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
        $instances = Instance::all()->sortBy('id');
        /** @var list<int> $instanceIds */
        $instanceIds = [];

        foreach ($instances as $instance) {
            if (! $instance instanceof Instance) {
                continue;
            }

            if ($this->isEligible($instance) && $this->callerCanAccess($caller, $instance, $permission)) {
                $instanceIds[] = $instance->id;
            }
        }

        return $instanceIds;
    }

    public function targetNode(Schedule|Instance $target): ?Node
    {
        $instance = $target instanceof Schedule ? $target->instance : $target;

        return $instance instanceof Instance ? $this->placement->nodeForInstance($instance) : null;
    }

    public function executionPath(Schedule $schedule): ?string
    {
        $schedule->loadMissing('instance');
        $config = $schedule->instance?->driver_config;

        return $config instanceof OrbitInstanceDriverConfigData && is_string($config->path) && $config->path !== ''
            ? $config->path
            : null;
    }

    private function authorizeExplicitSelection(
        AppSelection $selection,
        Node $caller,
        string $permission,
    ): AppSelection {
        $instance = $selection->instance;

        if (! $instance instanceof Instance) {
            throw new GatewayApiException(
                "Instance '{$selection->selector}' cannot run schedules.",
                'validation_failed',
                [
                    'field' => 'instance',
                    'reason' => 'instance_unavailable',
                    'app' => $selection->app->name,
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
                    'app' => $selection->app->name,
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
                    'app' => $selection->app->name,
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
     * @return list<Instance>
     */
    private function eligibleInstances(App $app): array
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

    private function isEligible(Instance $instance): bool
    {
        $node = $this->targetNode($instance);

        if (! $node instanceof Node) {
            return false;
        }

        return $node->isActive() && $this->nodeRoleAssignments->nodeCanServeGatewayOrAppHostWorkloads($node);
    }

    private function callerCanAccess(Node $caller, Instance $instance, string $permission): bool
    {
        $node = $this->targetNode($instance);

        return $node instanceof Node && $this->authorizer->allows($caller, $node, $permission);
    }
}
