<?php

declare(strict_types=1);

namespace App\Http\Authorization;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final class ServingNodeResolver
{
    public function resolve(Request $request, ServingNode $servingNode): ?Node
    {
        return match ($servingNode) {
            ServingNode::Gateway => $this->resolveGateway(),
            ServingNode::Target => $this->resolveTarget($request),
            ServingNode::AppOwning => $this->resolveAppOwning($request),
            ServingNode::WorkspaceOwning => $this->resolveWorkspaceOwning($request),
            ServingNode::Caller => $this->resolveCaller($request),
        };
    }

    private function resolveGateway(): ?Node
    {
        return Node::query()
            ->where('status', 'active')
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
        foreach (['app', 'name'] as $parameter) {
            $app = $this->appFromValue($this->requestValue($request, $parameter));

            if ($app instanceof OrbitApp) {
                return $app->node;
            }
        }

        return null;
    }

    private function resolveWorkspaceOwning(Request $request): ?Node
    {
        foreach (['workspace', 'name'] as $parameter) {
            $workspace = $this->workspaceFromValue(
                value: $this->requestValue($request, $parameter),
                app: $this->appFromValue($this->requestValue($request, 'app')),
            );

            if ($workspace instanceof Workspace) {
                return $workspace->app?->node;
            }
        }

        return null;
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

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Node::query()->whereKey($value)->first();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Node::query()
            ->where('name', $value)
            ->first();
    }

    private function appFromValue(mixed $value): ?OrbitApp
    {
        if ($value instanceof OrbitApp) {
            $value->loadMissing('node');

            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return OrbitApp::query()
                ->with('node')
                ->whereKey($value)
                ->first();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return OrbitApp::query()
            ->with('node')
            ->where('name', $value)
            ->first();
    }

    private function workspaceFromValue(mixed $value, ?OrbitApp $app = null): ?Workspace
    {
        if ($value instanceof Workspace) {
            $value->loadMissing('app.node');

            return $value;
        }

        if (! is_int($value) && (! is_string($value) || $value === '')) {
            return null;
        }

        return Workspace::query()
            ->with('app.node')
            ->when($app instanceof OrbitApp, fn ($query) => $query->where('app_id', $app->id))
            ->when(
                is_int($value) || ctype_digit((string) $value),
                fn ($query) => $query->whereKey($value),
                fn ($query) => $query->where('name', $value),
            )
            ->first();
    }
}
