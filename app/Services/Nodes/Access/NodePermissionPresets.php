<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

use InvalidArgumentException;

final class NodePermissionPresets
{
    /**
     * @return list<string>
     */
    public function permissions(string $name): array
    {
        return match ($name) {
            'agent-self' => $this->agentSelf(),
            'operator' => $this->operator(),
            'read-only' => $this->readOnly(),
            'developer' => $this->developer(),
            'admin' => $this->admin(),
            'gateway-admin' => ['*'],
            default => throw new InvalidArgumentException("Unknown preset [{$name}]."),
        };
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return [
            'agent-self',
            'operator',
            'read-only',
            'developer',
            'admin',
            'gateway-admin',
        ];
    }

    /**
     * Preset used by agent self grants.
     *
     * @return list<string>
     */
    private function agentSelf(): array
    {
        return [
            'doctor:verify',
            'node:read',
            'tool:read',
            'tool:restart',
            'tool:update:agent-tools',
        ];
    }

    /**
     * Default cross-node preset for agent nodes and general-purpose
     * fleet operators.
     *
     * @return list<string>
     */
    private function operator(): array
    {
        return [
            'app:read',
            'doctor:verify',
            'firewall_rule:read',
            'node:read',
            'tool:read',
            'tool:restart',
        ];
    }

    /**
     * Preset that grants only read permissions across the product surface.
     *
     * @return list<string>
     */
    private function readOnly(): array
    {
        return [
            'activity:read',
            'app:read',
            'cf:dns:list',
            'cf:zone:list',
            'deploy:read',
            'dns:list',
            'dns:resolve',
            'doctor:verify',
            'firewall_rule:read',
            'node:read',
            'php:read',
            'process:read',
            'proxy:read',
            'role:read',
            'schedule:read',
            'tool:read',
            'vpn:read',
            'workspace:read',
        ];
    }

    /**
     * Preset for developer workflows on app-development nodes.
     *
     * @return list<string>
     */
    private function developer(): array
    {
        return [
            'app:read',
            'app:write',
            'app:register',
            'app:remove',
            'app:prune',
            'app:agent',
            'app:root',
            'app:update',
            'app:new',
            'workspace:read',
            'workspace:write',
            'workspace:new',
            'workspace:setup',
            'workspace:remove',
            'workspace:history',
            'workspace:log',
            'process:read',
            'process:add',
            'process:edit',
            'process:remove',
            'process:start',
            'process:stop',
            'process:restart',
            'schedule:read',
            'schedule:add',
            'schedule:remove',
            'schedule:run',
            'schedule:write',
            'proxy:read',
            'proxy:add',
            'proxy:remove',
            'deploy:read',
            'deploy:run',
            'deploy:step',
            'tool:read',
            'tool:restart',
            'tool:update',
            'tool:update:agent-tools',
            'tool:install',
            'tool:remove',
            'tool:start',
            'tool:stop',
            'tool:reconfigure',
            'node:read',
            'doctor:verify',
            'dns:list',
            'dns:add',
            'dns:remove',
            'dns:resolve',
        ];
    }

    /**
     * Preset that grants full administrative authority over a serving node
     * short of fleet-wide gateway admin.
     *
     * @return list<string>
     */
    private function admin(): array
    {
        return [
            // Activity
            'activity:read',
            'activity:list',
            'activity:show',

            // App
            'app:read',
            'app:write',
            'app:register',
            'app:remove',
            'app:prune',
            'app:agent',
            'app:root',
            'app:update',
            'app:new',

            // Cloudflare
            'cf:cache:flush',
            'cf:cache:rule:add',
            'cf:cache:rule:remove',
            'cf:dns:add',
            'cf:dns:list',
            'cf:dns:remove',
            'cf:ssl:disable',
            'cf:ssl:enable',
            'cf:zone:list',

            // Deploy
            'deploy:read',
            'deploy:run',
            'deploy:step',
            'deploy:history',
            'deploy:log',

            // DNS
            'dns:add',
            'dns:list',
            'dns:remove',
            'dns:resolve',

            // Doctor
            'doctor:verify',
            'doctor:restore',
            'doctor:adopt',
            'doctor:fix',

            // Firewall
            'firewall_rule:read',
            'firewall_rule:write',

            // Node (read only)
            'node:read',

            // PHP
            'php:read',
            'php:write',
            'php:list',
            'php:use',

            // Process
            'process:read',
            'process:add',
            'process:edit',
            'process:remove',
            'process:start',
            'process:stop',
            'process:restart',

            // Proxy
            'proxy:read',
            'proxy:add',
            'proxy:remove',

            // Schedule
            'schedule:read',
            'schedule:add',
            'schedule:remove',
            'schedule:run',
            'schedule:write',

            // Tool
            'tool:read',
            'tool:restart',
            'tool:update',
            'tool:update:agent-tools',
            'tool:install',
            'tool:remove',
            'tool:start',
            'tool:stop',
            'tool:reconfigure',
            'tool:reload',
            'tool:credentials',

            // VPN
            'vpn:read',
            'vpn:write',

            // Workspace
            'workspace:read',
            'workspace:write',
            'workspace:new',
            'workspace:setup',
            'workspace:remove',
            'workspace:history',
            'workspace:log',
        ];
    }
}
