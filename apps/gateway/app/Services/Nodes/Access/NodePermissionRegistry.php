<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

use Illuminate\Support\Collection;

final class NodePermissionRegistry
{
    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [
            // Global wildcard
            '*',

            // Activity
            'activity:*',
            'activity:list',
            'activity:show',
            'activity:read',

            // App
            'app:*',
            'app:list',
            'app:show',
            'app:read',
            'app:write',
            'app:remove',
            'app:new',
            'app-development-setup-step:add',
            'app-development-setup-step:list',
            'app-development-setup-step:update',
            'app-development-setup-step:remove',

            // Instance
            'instance:*',
            'instance:credentials',
            'instance:list',
            'instance:show',
            'instance:read',
            'instance:write',
            'instance:register',
            'instance:remove',

            'instance:setup',
            'instance-setup-step:add',
            'instance-setup-step:list',
            'instance-setup-step:remove',

            'instance:root',
            'instance:update',
            'instance:worker',
            'instance:mount',

            // Cloudflare
            'cf:*',
            'cf:cache:flush',
            'cf:cache:rule:add',
            'cf:cache:rule:remove',
            'cf:dns:add',
            'cf:dns:list',
            'cf:dns:remove',
            'cf:ssl:disable',
            'cf:ssl:enable',
            'cf:zone:list',

            // Codex
            'codex:*',
            'codex:app',

            // Deploy
            'deploy:*',
            'deploy:history',
            'deploy:log',
            'deploy:read',
            'deploy:run',
            'deploy:step',

            // Database
            'database:*',
            'database:add',
            'database:add-user',
            'database:attach',
            'database:describe',
            'database:detach',
            'database:list',
            'database:query',
            'database:query:write',
            'database:read',
            'database:remove',
            'database:schema',
            'database:show',
            'database:tables',
            'database:update',
            'database:write',

            // DNS
            'dns:*',
            'dns:add',
            'dns:list',
            'dns:remove',
            'dns:resolve',

            // Extension
            'extension:*',
            'extension:read',
            'extension:enable',
            'extension:disable',

            // Doctor
            'doctor:*',
            'doctor:adopt',
            'doctor:fix',
            'doctor:restore',
            'doctor:verify',

            // Firewall
            'firewall_rule:*',
            'firewall_rule:read',
            'firewall_rule:write',

            // Node
            'node:*',

            'node:grant',
            'node:list',
            'node:migrate',
            'node:new',
            'node:permissions',
            'node:read',
            'node:reboot',
            'node:remove',
            'node:revoke',
            'node:show',
            'node:update',

            // PHP
            'php:*',
            'php:list',
            'php:read',
            'php:use',
            'php:write',

            // Process
            'process:*',
            'process:add',
            'process:list',
            'process:log',
            'process:read',
            'process:remove',
            'process:restart',
            'process:start',
            'process:stop',
            'process:update',

            // Proxy
            'proxy:*',
            'proxy:add',
            'proxy:list',
            'proxy:read',
            'proxy:remove',

            // Role
            'role:*',
            'role:add',
            'role:list',
            'role:read',
            'role:remove',

            // Schedule
            'schedule:*',
            'schedule:add',
            'schedule:list',
            'schedule:logs',
            'schedule:read',
            'schedule:remove',
            'schedule:run',
            'schedule:show',
            'schedule:write',

            // Solo
            'solo:*',
            'solo:status',
            'solo:help',
            'solo:tools',
            'solo:feedback',
            'solo:project:*',
            'solo:project:list',
            'solo:project:show',
            'solo:project:create',
            'solo:project:update',
            'solo:project:delete',
            'solo:process:*',
            'solo:process:read',
            'solo:process:spawn',
            'solo:process:input',
            'solo:process:lifecycle',
            'solo:process:delete',
            'solo:agent:*',
            'solo:agent-tool:list',
            'solo:agent:spawn',
            'solo:agent:setup',
            'solo:scratchpad:*',
            'solo:scratchpad:read',
            'solo:scratchpad:write',
            'solo:scratchpad:delete',
            'solo:scratchpad:transfer',
            'solo:todo:*',
            'solo:todo:read',
            'solo:todo:write',
            'solo:todo:delete',
            'solo:todo:lock',
            'solo:todo:comment',
            'solo:lock:*',
            'solo:timer:*',
            'solo:readiness:*',

            // Tool
            'tool:*',
            'tool:credentials',
            'tool:install',
            'tool:list',
            'tool:logs',
            'tool:read',
            'tool:reconfigure',
            'tool:reload',
            'tool:remove',
            'tool:restart',
            'tool:show',
            'tool:start',
            'tool:stop',
            'tool:update',
            'tool:update:agent-tools',

            // VPN
            'vpn:*',
            'vpn:read',
            'vpn:write',

            // Workspace
            'workspace:*',
            'workspace:history',
            'workspace:list',
            'workspace:new',
            'workspace:read',
            'workspace:remove',
            'workspace:run:log',
            'workspace:setup',
            'workspace:show',
            'workspace:write',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function implications(): array
    {
        return [
            'activity:read' => ['activity:list', 'activity:show'],
            'app:read' => ['app:list', 'app:show', 'app-development-setup-step:list'],
            'app:write' => ['app-development-setup-step:add', 'app-development-setup-step:update', 'app-development-setup-step:remove'],
            'instance:read' => ['instance:list', 'instance:show', 'instance-setup-step:list'],
            'instance:write' => ['instance:setup', 'instance-setup-step:add', 'instance-setup-step:remove'],
            'database:query:write' => ['database:query'],
            'database:read' => [
                'database:list',
                'database:show',
                'database:tables',
                'database:schema',
                'database:describe',
            ],
            'database:write' => [
                'database:add',
                'database:add-user',
                'database:update',
                'database:remove',
                'database:attach',
                'database:detach',
            ],
            'codex:*' => ['codex:app'],
            'deploy:read' => ['deploy:history', 'deploy:log'],
            'extension:*' => ['extension:read', 'extension:enable', 'extension:disable'],
            'node:read' => ['node:list', 'node:show'],
            'php:read' => ['php:list'],
            'process:read' => ['process:list', 'process:log'],
            'proxy:read' => ['proxy:list'],
            'role:read' => ['role:list'],
            'schedule:read' => ['schedule:list', 'schedule:show', 'schedule:logs'],
            'tool:read' => ['tool:list', 'tool:show', 'tool:logs'],
            'tool:update' => ['tool:update:agent-tools'],
            'vpn:read' => [],
            'workspace:read' => ['workspace:list', 'workspace:show'],
        ];
    }

    public function isKnown(string $permission): bool
    {
        if ($permission === '*') {
            return true;
        }

        if (str_ends_with($permission, ':*')) {
            if (in_array($permission, $this->all(), true)) {
                return true;
            }

            $namespace = substr($permission, 0, -2);

            return $this->namespaces()->contains($namespace);
        }

        return in_array($permission, $this->all(), true);
    }

    /**
     * Return all permissions implied by the given permission.
     *
     * @return list<string>
     */
    public function impliedBy(string $permission): array
    {
        $implications = $this->implications();

        if (isset($implications[$permission])) {
            return $implications[$permission];
        }

        if ($permission === '*') {
            return array_values(array_filter(
                $this->all(),
                static fn (string $p): bool => $p !== '*',
            ));
        }

        if (str_ends_with($permission, ':*')) {
            $namespace = substr($permission, 0, -2);

            return array_values(array_filter(
                $this->all(),
                static fn (string $p): bool => (
                    $p !== '*'
                    && ! str_ends_with($p, ':*')
                    && str_starts_with($p, $namespace.':')
                ),
            ));
        }

        return [];
    }

    /**
     * @param  list<string>  $permissions
     */
    public function allows(array $permissions, string $required): bool
    {
        return array_any(
            $permissions,
            fn ($permission) => $permission === $required || $this->isCoveredBy($required, $permission),
        );
    }

    /**
     * @return Collection<int, string>
     */
    public function namespaces(): Collection
    {
        $namespaces = [];

        foreach ($this->all() as $permission) {
            if ($permission === '*') {
                continue;
            }

            $parts = explode(':', $permission);
            $namespaces[] = $parts[0];
        }

        return collect(array_unique($namespaces))->values();
    }

    /**
     * Check if a permission is covered by another permission.
     *
     * For example, `tool:list` is covered by `tool:read`, and `node:show` is covered by `node:*`.
     */
    public function isCoveredBy(string $permission, string $coveringPermission): bool
    {
        if ($coveringPermission === '*') {
            return $permission !== '*';
        }

        if (str_ends_with($coveringPermission, ':*')) {
            $namespace = substr($coveringPermission, 0, -2);

            return $permission !== $coveringPermission && str_starts_with($permission, $namespace.':');
        }

        $implied = $this->impliedBy($coveringPermission);

        return in_array($permission, $implied, true);
    }
}
