<?php

declare(strict_types=1);

use App\Services\Nodes\Access\ProjectInstancePermissionMigrator;

it('expands app permissions into their project and instance equivalents', function (
    array $permissions,
    array $expected,
): void {
    expect(new ProjectInstancePermissionMigrator()->migrate($permissions))->toBe($expected);
})->with([
    'read' => [
        ['app:read'],
        ['app:read', 'project:read', 'instance:read'],
    ],
    'write' => [
        ['app:write'],
        ['app:write', 'project:write', 'instance:write'],
    ],
    'wildcard' => [
        ['app:*'],
        ['app:*', 'project:*', 'instance:*'],
    ],
    'specialized instance permissions' => [
        ['app:register', 'app:credentials', 'app:mount'],
        [
            'app:register',
            'instance:register',
            'app:credentials',
            'instance:credentials',
            'app:mount',
            'instance:mount',
        ],
    ],
    'command permissions' => [
        ['app:list', 'app:show', 'app:new', 'app:remove', 'app:setup', 'app-setup-step:add'],
        [
            'app:list',
            'project:list',
            'app:show',
            'project:show',
            'app:new',
            'project:new',
            'app:remove',
            'project:remove',
            'app:setup',
            'instance:setup',
            'app-setup-step:add',
            'instance-setup-step:add',
        ],
    ],
    'deduplication and passthrough' => [
        ['node:read', 'app:read', 'project:read', 'instance:read', 'node:read'],
        ['node:read', 'app:read', 'project:read', 'instance:read'],
    ],
]);
