<?php

declare(strict_types=1);

namespace Orbit\Core\Extensions;

use InvalidArgumentException;

final readonly class OrbitExtensionRegistry
{
    private const array SoloCommands = [
        'solo:status',
        'solo:help',
        'solo:tools',
        'solo:smoke-test',
        'solo:feedback',
        'solo:project:list',
        'solo:project:show',
        'solo:project:status',
        'solo:project:stats',
        'solo:project:create',
        'solo:project:rename',
        'solo:project:select',
        'solo:project:delete',
        'solo:process:list',
        'solo:process:show',
        'solo:process:output',
        'solo:process:search',
        'solo:process:ports',
        'solo:process:select',
        'solo:process:input',
        'solo:process:spawn',
        'solo:agent:spawn',
        'solo:process:start',
        'solo:process:stop',
        'solo:process:restart',
        'solo:process:clear-output',
        'solo:process:rename',
        'solo:process:close',
        'solo:command:start-all',
        'solo:command:stop-all',
        'solo:command:restart-all',
        'solo:agent-tool:list',
        'solo:agent:setup',
        'solo:scratchpad:list',
        'solo:scratchpad:show',
        'solo:scratchpad:find',
        'solo:scratchpad:create',
        'solo:scratchpad:write',
        'solo:scratchpad:append',
        'solo:scratchpad:append-section',
        'solo:scratchpad:edit',
        'solo:scratchpad:rename',
        'solo:scratchpad:archive',
        'solo:scratchpad:clear',
        'solo:scratchpad:delete',
        'solo:scratchpad:tags',
        'solo:scratchpad:tag:add',
        'solo:scratchpad:tag:remove',
        'solo:scratchpad:load-file',
        'solo:scratchpad:save-file',
        'solo:scratchpad:transfer',
        'solo:todo:list',
        'solo:todo:show',
        'solo:todo:create',
        'solo:todo:update',
        'solo:todo:complete',
        'solo:todo:reopen',
        'solo:todo:delete',
        'solo:todo:lock',
        'solo:todo:unlock',
        'solo:todo:transfer',
        'solo:todo:tags',
        'solo:todo:tag:add',
        'solo:todo:tag:remove',
        'solo:todo:blocker:add',
        'solo:todo:blocker:remove',
        'solo:todo:blockers:set',
        'solo:todo:comment:list',
        'solo:todo:comment:add',
        'solo:todo:comment:update',
        'solo:todo:comment:delete',
        'solo:lock:status',
        'solo:lock:acquire',
        'solo:lock:release',
        'solo:timer:list',
        'solo:timer:set',
        'solo:timer:cancel',
        'solo:timer:pause',
        'solo:timer:resume',
        'solo:timer:idle-any',
        'solo:timer:idle-all',
        'solo:service:list',
        'solo:port:wait',
    ];

    private const array SoloPermissions = [
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
    ];

    /** @var list<OrbitExtensionDefinition> */
    private array $definitions;

    /** @var array<string, OrbitExtensionDefinition> */
    private array $definitionsBySlug;

    public function __construct()
    {
        $this->definitions = [
            new OrbitExtensionDefinition(
                slug: 'cloudflare',
                label: 'Cloudflare',
                description: 'Cloudflare DNS, cache, and SSL operations through the gateway.',
                commands: [
                    'cf-zone:list',
                    'cf-dns:list',
                    'cf-dns:add',
                    'cf-dns:remove',
                    'cf-cache:flush',
                    'cf-cache-rule:add',
                    'cf-cache-rule:remove',
                    'cf-ssl:enable',
                    'cf-ssl:disable',
                ],
                permissions: [
                    'cf:*',
                    'cf:zone:list',
                    'cf:dns:list',
                    'cf:dns:add',
                    'cf:dns:remove',
                    'cf:cache:flush',
                    'cf:cache:rule:add',
                    'cf:cache:rule:remove',
                    'cf:ssl:enable',
                    'cf:ssl:disable',
                ],
                gatewayRoutePrefixes: [
                    '/api/cloudflare',
                ],
            ),
            new OrbitExtensionDefinition(
                slug: 'codex',
                label: 'Codex',
                description: 'Codex App project registration on eligible operator nodes.',
                commands: [
                    'codex:app',
                ],
                permissions: [
                    'codex:*',
                    'codex:app',
                ],
                gatewayRoutePrefixes: [
                    '/api/codex',
                ],
            ),
            new OrbitExtensionDefinition(
                slug: 'solo',
                label: 'Solo',
                description: 'Solo API command family through the Orbit gateway.',
                commands: self::SoloCommands,
                permissions: self::SoloPermissions,
                gatewayRoutePrefixes: [
                    '/api/solo',
                ],
            ),
        ];

        $definitionsBySlug = [];

        foreach ($this->definitions as $definition) {
            $definitionsBySlug[$definition->slug] = $definition;
        }

        $this->definitionsBySlug = $definitionsBySlug;
    }

    /**
     * @return list<OrbitExtensionDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_map(
            static fn (OrbitExtensionDefinition $definition): string => $definition->slug,
            $this->definitions,
        );
    }

    public function get(string $slug): ?OrbitExtensionDefinition
    {
        return $this->definitionsBySlug[$slug] ?? null;
    }

    public function require(string $slug): OrbitExtensionDefinition
    {
        $definition = $this->get($slug);

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown Orbit extension [{$slug}].");
        }

        return $definition;
    }

    public function extensionForCommand(string $command): ?OrbitExtensionDefinition
    {
        foreach ($this->definitions as $definition) {
            if (in_array($command, $definition->commands, strict: true)) {
                return $definition;
            }
        }

        return null;
    }
}
