<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceRoleGuard;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class DatabaseConnectionTargetResolver
{
    public function __construct(
        private WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    public function resolveNode(?string $selector): ?Node
    {
        if ($selector === null || trim($selector) === '') {
            return null;
        }

        return Node::query()
            ->where('name', trim($selector))
            ->first();
    }

    public function resolveApp(?string $selector): ?App
    {
        if ($selector === null || trim($selector) === '') {
            return null;
        }

        $selector = trim($selector);

        return App::query()
            ->where('name', $selector)
            ->orWhere('domain', $selector)
            ->first();
    }

    public function resolveWorkspace(?string $selector): ?Workspace
    {
        if ($selector === null || trim($selector) === '') {
            return null;
        }

        return Workspace::query()
            ->where('name', trim($selector))
            ->first();
    }

    public function resolveWorkspaceForCaller(?string $selector, Node $caller): ?Workspace
    {
        $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($caller);
        $workspace = $this->resolveWorkspace($selector);

        if ($workspace instanceof Workspace) {
            $this->workspaceRoleGuard->ensureWorkspaceSupported($workspace);
        }

        return $workspace;
    }

    public function ensureWorkspaceSupportedForCaller(Workspace $workspace, Node $caller): void
    {
        $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($caller);
        $this->workspaceRoleGuard->ensureWorkspaceSupported($workspace);
    }

    public function workspaceIsSupportedForCaller(Workspace $workspace, Node $caller): bool
    {
        try {
            $this->ensureWorkspaceSupportedForCaller($workspace, $caller);

            return true;
        } catch (WorkspaceUnsupportedForProduction) {
            return false;
        }
    }

    public function resolveInstance(App $app, ?string $selector): ?Instance
    {
        if ($selector === null || trim($selector) === '') {
            return null;
        }

        $instance = $app
            ->instances()
            ->where('name', trim($selector))
            ->first();

        return $instance instanceof Instance ? $instance : null;
    }

    public function resolveInstanceSelector(?string $selector): ?Instance
    {
        if ($selector === null || ! str_contains(trim($selector), '.')) {
            return null;
        }

        [$appName, $instanceName] = explode(separator: '.', string: trim($selector), limit: 2);
        $app = App::query()->where('name', $appName)->first();

        if (! $app instanceof App) {
            return null;
        }

        return $this->resolveInstance($app, $instanceName);
    }

    public function validEnvPrefix(?string $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        return preg_match('/^[A-Z][A-Z0-9_]*$/', $value) === 1;
    }
}
