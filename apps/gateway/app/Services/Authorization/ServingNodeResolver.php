<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Data\Apps\AppSelection;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Workspace;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Processes\ProcessAppHostnameResolver;
use App\Services\Runtime\OrbitHostCwdContext;
use App\Services\Runtime\OrbitHostCwdResolver;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Orbit\Sdk\Laravel\GatewayApiException;

final class ServingNodeResolver
{
    public function resolve(Request $request, ServingNode $servingNode): ?Node
    {
        return match ($servingNode) {
            ServingNode::Gateway => $this->resolveGateway(),
            ServingNode::Target => $this->resolveTarget($request),
            ServingNode::AppOwning => $this->resolveAppOwning($request),
            ServingNode::InstanceOwning => $this->resolveInstanceOwning($request),
            ServingNode::WorkspaceOwning => $this->resolveWorkspaceOwning($request),
            ServingNode::Caller => $this->resolveCaller($request),
        };
    }

    private function resolveGateway(): ?Node
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn ($query) => $query
                ->where('role', NodeRoleName::Gateway->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->first();
    }

    private function resolveTarget(Request $request): ?Node
    {
        foreach (['node', 'name', 'target', 'target_node'] as $parameter) {
            $node = $this->nodeFromValue($this->requestValue($request, $parameter));

            if ($node instanceof Node) {
                return $node;
            }
        }

        return null;
    }

    private function resolveAppOwning(Request $request): ?Node
    {
        // Process list/lifecycle `app` is a proxy-route hostname selector. Resolve it
        // before unscoped process-name lookup so duplicate process names on other
        // nodes cannot win grant authorization for the wrong serving node.
        $hostnameNode = $this->nodeFromAppHostnameSelector($this->requestValue($request, 'app'));

        if ($hostnameNode instanceof Node) {
            return $hostnameNode;
        }

        foreach (['app', 'instance'] as $parameter) {
            $node = $this->appNodeFromValue($this->requestValue($request, $parameter));

            if ($node instanceof Node) {
                return $node;
            }
        }

        foreach (['process', 'process_name', 'name'] as $parameter) {
            $process = $this->processFromValue(
                value: $this->requestValue($request, $parameter),
                app: $this->appFromValue($this->requestValue($request, 'instance')),
            );

            if ($process instanceof OrbitProcess) {
                $processApp = $this->appForProcess($process);

                return $processApp instanceof App
                    ? app(WorkspacePlacement::class)->runtimeNode($processApp, $process->instance)
                    : null;
            }
        }

        $app = $this->appFromValue($this->requestValue($request, 'name'));

        if ($app instanceof App) {
            return app(WorkspacePlacement::class)->runtimeNode($app, null);
        }

        foreach (['host_cwd', 'path', 'caller_cwd'] as $parameter) {
            $app = $this->appFromPath($this->requestValue($request, $parameter));

            if ($app instanceof App) {
                return app(WorkspacePlacement::class)->runtimeNode($app, null);
            }
        }

        return null;
    }

    /**
     * Resolve an app/workspace proxy-route hostname to its concrete node.
     *
     * Invalid or non-hostname values (including legacy project aliases in
     * `app`) return null so unrelated routes keep their existing fall-through.
     */
    private function nodeFromAppHostnameSelector(mixed $value): ?Node
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return app(ProcessAppHostnameResolver::class)->resolve(trim($value))->node;
        } catch (GatewayApiException) {
            return null;
        }
    }

    private function resolveWorkspaceOwning(Request $request): ?Node
    {
        $selection = $this->appSelectionFromValue($this->requestValue($request, 'instance'));

        foreach (['workspace', 'name'] as $parameter) {
            $workspace = $this->workspaceFromValue(
                value: $this->requestValue($request, $parameter),
                app: $selection?->app ?? $this->appFromValue($this->requestValue($request, 'instance')),
            );

            if ($workspace instanceof Workspace) {
                if (
                    ! $selection instanceof AppSelection
                    || app(AppSelectorResolver::class)->matchesWorkspace($workspace, $selection)
                ) {
                    return $this->workspaceNode($workspace);
                }
            }
        }

        foreach (['host_cwd', 'path', 'caller_cwd'] as $parameter) {
            $workspace = $this->workspaceFromPath($this->requestValue($request, $parameter));

            if ($workspace instanceof Workspace) {
                return $this->workspaceNode($workspace);
            }
        }

        return null;
    }

    private function resolveInstanceOwning(Request $request): ?Node
    {
        $instanceSelector = $this->requestValue($request, 'instance');
        $selection = $this->appSelectionFromValue($instanceSelector);

        if (! $selection instanceof AppSelection) {
            $selection = $this->instanceSelectionFromValues(
                appSelector: $this->requestValue($request, 'app'),
                instanceSelector: $instanceSelector,
            );
        }

        if (! $selection instanceof AppSelection) {
            return null;
        }

        try {
            $selection = app(AppSelectorResolver::class)->requireInstance($selection);
        } catch (AppSelectionResolutionFailed) {
            return null;
        }

        if ($selection->instance === null) {
            return null;
        }

        $node = app(WorkspacePlacement::class)->nodeForInstance($selection->instance);

        return $node instanceof Node ? $node : $this->resolveGateway();
    }

    private function instanceSelectionFromValues(mixed $appSelector, mixed $instanceSelector): ?AppSelection
    {
        $selection = $this->appSelectionFromValue($appSelector);

        if (
            ! $selection instanceof AppSelection
            || ! is_string($instanceSelector)
            || trim($instanceSelector) === ''
        ) {
            return $selection;
        }

        $instanceName = trim($instanceSelector);
        $instance = $selection
            ->app
            ->instances
            ->first(
                static fn (Instance $candidate): bool => $candidate->name === $instanceName,
            );

        if (! $instance instanceof Instance) {
            return null;
        }

        return new AppSelection(
            app: $selection->app,
            instance: $instance,
            selector: $selection->selector,
            instanceSelector: $instanceName,
        );
    }

    private function resolveCaller(Request $request): ?Node
    {
        $caller = $request->user();

        return $caller instanceof Node ? $caller : null;
    }

    private function requestValue(Request $request, string $name): mixed
    {
        $route = $request->route();

        if ($route instanceof Route && $route->hasParameter($name)) {
            return $route->parameter($name);
        }

        if ($request->request->has($name)) {
            return $request->request->get($name);
        }

        if ($request->query->has($name)) {
            return $request->query->get($name);
        }

        return null;
    }

    private function nodeFromValue(mixed $value): ?Node
    {
        if ($value instanceof Node) {
            return $value;
        }

        if (is_int($value) || is_string($value) && ctype_digit($value)) {
            return Node::query()->whereKey($value)->first();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Node::query()
            ->where('name', $value)
            ->first();
    }

    private function appFromValue(mixed $value): ?App
    {
        $selection = $this->appSelectionFromValue($value);

        if ($selection !== null) {
            return $selection->app;
        }

        if ($value instanceof App) {
            return $value;
        }

        if (is_int($value) || is_string($value) && ctype_digit($value)) {
            return App::query()
                ->whereKey($value)
                ->first();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $app = App::query()
            ->where('name', $value)
            ->first();

        if ($app instanceof App) {
            return $app;
        }

        // App owns no domain column: domain-value resolution matches instance
        // hosts via appHasUrl below instead of a shadow App::domain query.
        return App::query()
            ->with('instances')
            ->get()
            ->first(fn (App $app): bool => app(WorkspacePlacement::class)->appHasUrl($app, $value));
    }

    private function appNodeFromValue(mixed $value): ?Node
    {
        $selection = $this->appSelectionFromValue($value);

        if ($selection !== null) {
            return app(WorkspacePlacement::class)->runtimeNode($selection->app, $selection->instance);
        }

        $app = $this->appFromValue($value);

        return $app instanceof App ? app(WorkspacePlacement::class)->runtimeNode($app, null) : null;
    }

    private function appSelectionFromValue(mixed $value): ?AppSelection
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return app(AppSelectorResolver::class)->resolve($value);
        } catch (AppSelectionResolutionFailed) {
            return null;
        }
    }

    private function processFromValue(mixed $value, ?App $app = null): ?OrbitProcess
    {
        if ($value instanceof OrbitProcess) {
            $value->loadMissing('owner');

            return $value;
        }

        if (! is_int($value) && (! is_string($value) || $value === '')) {
            return null;
        }

        return OrbitProcess::query()
            ->with('owner')
            ->when($app instanceof App, fn ($query) => $query->ownedBy($app))
            ->when(
                is_int($value) || ctype_digit($value),
                fn ($query) => $query->whereKey($value),
                fn ($query) => $query->where('name', $value),
            )
            ->first();
    }

    private function appForProcess(OrbitProcess $process): ?App
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof App) {
            return $process->owner;
        }

        if ($process->owner instanceof Workspace) {
            $process->owner->loadMissing('app');

            return $process->owner->app;
        }

        return null;
    }

    private function workspaceFromValue(mixed $value, ?App $app = null): ?Workspace
    {
        if ($value instanceof Workspace) {
            $value->loadMissing('app');

            return $value;
        }

        if (! is_int($value) && (! is_string($value) || $value === '')) {
            return null;
        }

        $query = Workspace::query()
            ->with(['app.instances', 'instance'])
            ->when($app instanceof App, fn ($query) => $query->where('app_id', $app?->id))
            ->when(
                is_int($value) || ctype_digit($value),
                fn ($query) => $query->whereKey($value),
                fn ($query) => $query->where('name', $value),
            );

        $matches = $query->limit(2)->get();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    private function workspaceNode(Workspace $workspace): ?Node
    {
        return app(WorkspacePlacement::class)->nodeForWorkspace($workspace);
    }

    private function workspaceFromPath(mixed $value): ?Workspace
    {
        $context = $this->resolveCwd($value);

        return $context?->workspace;
    }

    private function appFromPath(mixed $value): ?App
    {
        $context = $this->resolveCwd($value);

        if ($context === null) {
            return null;
        }

        // Workspace match wins over parent-app match when the cwd is inside
        // a workspace tree. AppOwning authorization mirrors that preference:
        // a path inside a workspace authorizes against the workspace's
        // parent app (which is the same node), not "some random app whose
        // path happens to be a string prefix".
        return $context->app;
    }

    private function resolveCwd(mixed $value): ?OrbitHostCwdContext
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return app(OrbitHostCwdResolver::class)->resolve($value);
    }
}
