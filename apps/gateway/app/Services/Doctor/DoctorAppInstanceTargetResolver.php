<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorAppInstanceTarget;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class DoctorAppInstanceTargetResolver
{
    public function __construct(
        private AppSelectorResolver $selectorResolver,
        private WorkspacePlacement $placement,
        private NodeAccessAuthorizer $authorizer,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function resolve(?string $selector, Node $caller, string $permission): ?DoctorAppInstanceTarget
    {
        if ($selector === null) {
            return null;
        }

        $hasGlobalVisibility = $this->hasGlobalVisibility($caller);
        $instanceIsVisible = fn (AppInstance $instance): bool => $this->selectorResolver
            ->instanceIsVisibleTo($caller, $instance, $permission);
        $selection = $this->selectorResolver->resolve(
            $selector,
            $hasGlobalVisibility ? null : $instanceIsVisible,
        );

        if ($selection === null) {
            throw new AppSelectionResolutionFailed(
                'instance.not_found',
                "Instance '{$selector}' was not found.",
                ['field' => 'instance', 'instance' => $selector],
            );
        }

        $wasExplicitInstance = $selection->instance instanceof AppInstance;
        $selection = $this->selectorResolver->requireInstance(
            $selection,
            instanceIsVisible: $hasGlobalVisibility ? null : $instanceIsVisible,
        );
        $instance = $selection->instance;

        if (! $instance instanceof AppInstance) {
            throw new AppSelectionResolutionFailed(
                'validation_failed',
                "Project '{$selection->app->name}' requires a concrete instance selector.",
                [
                    'field' => 'instance',
                    'reason' => 'instance_required',
                    'project' => $selection->app->name,
                ],
            );
        }

        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            if (! $hasGlobalVisibility) {
                throw $this->hiddenSelectionFailure(
                    $selection->app->name,
                    $selector,
                    $wasExplicitInstance,
                    $selection->instanceSelector,
                );
            }

            throw new AppSelectionResolutionFailed(
                'validation_failed',
                "Instance '{$selection->app->name}.{$instance->name}' does not resolve an Orbit serving node.",
                [
                    'field' => 'instance',
                    'reason' => 'instance_unavailable',
                    'project' => $selection->app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        if (! $hasGlobalVisibility && ! $instanceIsVisible($instance)) {
            throw $this->hiddenSelectionFailure(
                $selection->app->name,
                $selector,
                $wasExplicitInstance,
                $selection->instanceSelector,
            );
        }

        return new DoctorAppInstanceTarget($selection->app, $instance, $node);
    }

    private function hasGlobalVisibility(Node $caller): bool
    {
        if ($this->nodeRoleAssignments->nodeIsGateway($caller)) {
            return true;
        }

        $gateway = $this->nodeRoleAssignments->activeGatewayNodeQuery()->first();

        if (! $gateway instanceof Node) {
            return false;
        }

        $grant = NodeAccess::query()
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $gateway->id)
            ->first();

        return $grant instanceof NodeAccess && in_array('*', $grant->permissions ?? ['*'], true);
    }

    private function hiddenSelectionFailure(
        string $app,
        string $selector,
        bool $wasExplicitInstance,
        ?string $instance,
    ): AppSelectionResolutionFailed {
        if (! $wasExplicitInstance) {
            return new AppSelectionResolutionFailed(
                'instance.not_found',
                "Instance '{$selector}' was not found.",
                ['field' => 'instance', 'instance' => $selector],
            );
        }

        return new AppSelectionResolutionFailed(
            'validation_failed',
            "Instance '{$selector}' not found.",
            [
                'field' => 'instance',
                'project' => $app,
                'instance' => $instance,
            ],
        );
    }
}
