<?php

declare(strict_types=1);

namespace Orbit\Core\Extensions;

use InvalidArgumentException;

final readonly class OrbitExtensionRegistry
{
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
                commands: [],
                permissions: [],
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
