<?php

declare(strict_types=1);

use App\Services\Nodes\Access\NodePermissionRegistry;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

it('registers projects and instances as the canonical workload resources', function (): void {
    $routeUris = collect(Route::getRoutes())
        ->map(fn (LaravelRoute $route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/'))
        ->values();

    expect($routeUris)
        ->toContain('api/projects', 'api/instances')
        ->not->toContain('api/apps');
});

it('registers separate project and instance permissions without app aliases', function (): void {
    $permissions = app(NodePermissionRegistry::class)->all();

    expect($permissions)
        ->toContain(
            'project:*',
            'project:read',
            'project:write',
            'instance:*',
            'instance:read',
            'instance:write',
            'instance:register',
            'instance:credentials',
            'instance:mount',
        )
        ->not->toContain(
            'app:*',
            'app:read',
            'app:write',
            'app:register',
            'app:credentials',
            'app:mount',
        );
});

it('keeps agent-facing command references on project and instance vocabulary', function (): void {
    $paths = [
        '.agents/skills/orbit/SKILL.md',
        '.agents/skills/orbit/references/activity.md',
        '.agents/skills/orbit/references/agent-ide.md',
        '.agents/skills/orbit/references/app.md',
        '.agents/skills/orbit/references/concepts.md',
        '.agents/skills/orbit/references/database.md',
        '.agents/skills/orbit/references/operation.md',
        '.agents/skills/orbit/references/php.md',
        '.agents/skills/orbit/references/process.md',
        '.agents/skills/orbit/references/schedule.md',
        '.agents/skills/orbit/references/tool.md',
        '.agents/skills/orbit/references/workspace.md',
        'apps/docs/content/domains/authorization-matrix.md',
    ];

    $content = collect($paths)
        ->map(fn (string $path): string => (string) file_get_contents(repo_path($path)))
        ->implode("\n");

    expect($content)
        ->toContain('project:new', 'project:list', 'instance:list', 'instance:setup')
        ->not->toMatch(
            '/(?:\bapp:(?:new|list|show|remove|register|root|prune|agent-ide|worker|mount|analytics|websocket|env|setup)\b|--app(?:=|\b))/',
        );
});
