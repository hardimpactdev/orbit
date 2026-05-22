<?php

declare(strict_types=1);

use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use Tests\TestCase;

uses(TestCase::class);

describe('orbit caddy container', function (): void {
    it('uses deterministic orbit-caddy container defaults', function (): void {
        $container = OrbitCaddyContainer::default(new OrbitContainerNames);

        expect($container->name())->toBe('orbit-caddy')
            ->and($container->image())->toBe('caddy:2-alpine')
            ->and($container->restartPolicy())->toBe('unless-stopped')
            ->and($container->network())->toBe('orbit-network')
            ->and($container->publishedPorts())->toBe([])
            ->and($container->mounts())->toBe([
                ['source' => '/etc/caddy/Caddyfile', 'target' => '/etc/caddy/Caddyfile', 'read_only' => true],
                ['source' => '/etc/caddy/orbit', 'target' => '/etc/caddy/orbit', 'read_only' => true],
                ['source' => '/etc/caddy/sites', 'target' => '/etc/caddy/sites', 'read_only' => true],
                ['source' => '/etc/orbit', 'target' => '/etc/orbit', 'read_only' => true],
                ['source' => '/home', 'target' => '/home', 'read_only' => true],
            ])
            ->and($container->networkAliases())->toBe(['orbit-caddy'])
            ->and($container->extraHosts())->toBe(['host.docker.internal' => 'host-gateway'])
            ->and($container->labels())->toMatchArray([
                'orbit.managed' => 'true',
                'orbit.container.kind' => 'caddy',
            ])
            ->and($container->spec())->toMatchArray([
                'name' => 'orbit-caddy',
                'image' => 'caddy:2-alpine',
                'network' => 'orbit-network',
                'restart_policy' => 'unless-stopped',
                'published_ports' => [],
                'mounts' => [
                    ['source' => '/etc/caddy/Caddyfile', 'target' => '/etc/caddy/Caddyfile', 'read_only' => true],
                    ['source' => '/etc/caddy/orbit', 'target' => '/etc/caddy/orbit', 'read_only' => true],
                    ['source' => '/etc/caddy/sites', 'target' => '/etc/caddy/sites', 'read_only' => true],
                    ['source' => '/etc/orbit', 'target' => '/etc/orbit', 'read_only' => true],
                    ['source' => '/home', 'target' => '/home', 'read_only' => true],
                ],
                'network_aliases' => ['orbit-caddy'],
                'extra_hosts' => ['host.docker.internal' => 'host-gateway'],
            ]);
    });

    it('exposes host paths required by managed route artifacts', function (): void {
        $targets = collect(OrbitCaddyContainer::default()->mounts())
            ->pluck('target')
            ->all();

        $expectedRouteArtifactRoots = [
            '/etc/caddy/sites',
            '/etc/caddy/orbit',
            '/etc/orbit',
            '/home',
        ];

        foreach ($expectedRouteArtifactRoots as $root) {
            expect($targets)->toContain($root);
        }
    });

    it('publishes public HTTP and HTTPS ports for ingress containers without leaking the private backend port', function (): void {
        $container = OrbitCaddyContainer::forPublicIngress(names: new OrbitContainerNames);

        expect($container->publishedPorts())->toBe([
            '80:80',
            '443:443',
            '443:443/udp',
        ]);
    });

    it('keeps the private backend port WireGuard-only when ingress is co-located with app-production', function (): void {
        $container = OrbitCaddyContainer::forPublicIngress('10.6.0.50');

        expect($container->publishedPorts())->toBe([
            '80:80',
            '443:443',
            '443:443/udp',
            '10.6.0.50:8081:8081',
        ]);

        $publicPorts = collect($container->publishedPorts())
            ->reject(fn (string $port): bool => str_starts_with($port, '10.6.0.50:'))
            ->values()
            ->all();

        foreach ($publicPorts as $publicPort) {
            expect($publicPort)->not->toContain((string) OrbitCaddyContainer::PrivateBackendPort);
        }
    });

    it('binds private role listeners to the node WireGuard address including the private backend port', function (): void {
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50', new OrbitContainerNames);

        expect($container->publishedPorts())->toBe([
            '10.6.0.50:80:80',
            '10.6.0.50:443:443',
            '10.6.0.50:443:443/udp',
            '10.6.0.50:8081:8081',
        ]);
    });

    it('round-trips extra hosts through config', function (): void {
        $container = OrbitCaddyContainer::fromConfig([
            'extra_hosts' => [
                'host.docker.internal' => 'host-gateway',
                'legacy.internal' => '127.0.0.1',
            ],
        ]);

        expect($container->extraHosts())->toBe([
            'host.docker.internal' => 'host-gateway',
            'legacy.internal' => '127.0.0.1',
        ]);
    });
});
