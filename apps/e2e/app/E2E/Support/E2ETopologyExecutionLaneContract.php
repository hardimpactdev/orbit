<?php

declare(strict_types=1);

namespace App\E2E\Support;

final class E2ETopologyExecutionLaneContract
{
    private const string SourceRoot = '/home/orbit/orbit';

    private const string OrbitBinary = 'orbit-binary';

    /**
     * @var list<string>
     */
    private const array HostPhpRoles = [
        'app-dev',
        'app-prod',
    ];

    /**
     * @param  list<string>  $roles
     */
    public static function artifactProdRequiresHostPhp(array $roles): bool
    {
        return array_any(
            self::normalizeRoles($roles),
            fn (string $role): bool => in_array($role, self::HostPhpRoles, true),
        );
    }

    public static function sourceDevEntrypointScript(): string
    {
        return implode("\n", [
            'install -d /usr/local/bin',
            sprintf('ln -sfn %s/apps/cli/orbit /usr/local/bin/orbit', self::SourceRoot),
        ]);
    }

    public static function sourceDevDependencyHydrationScript(): string
    {
        return implode("\n", [
            sprintf('cd %s', self::SourceRoot),
            'composer --working-dir=apps/gateway install --no-interaction',
            'composer --working-dir=apps/cli install --no-interaction',
            'test -f apps/gateway/vendor/autoload.php',
            'test -f apps/cli/vendor/autoload.php',
        ]);
    }

    /**
     * @param  list<string>  $roles
     */
    public static function artifactProdBootstrapScript(array $roles): string
    {
        $roles = self::normalizeRoles($roles);

        $lines = [
            'install -d /usr/local/bin',
            sprintf('install -m 0755 %s /usr/local/bin/orbit', self::OrbitBinary),
        ];

        if (in_array('gateway', $roles, true)) {
            $lines = [
                ...$lines,
                'if [ -n "${ORBIT_GATEWAY_IMAGE_ARCHIVE:-}" ]; then docker load -i "${ORBIT_GATEWAY_IMAGE_ARCHIVE}"; fi',
                'ORBIT_GATEWAY_IMAGE=${ORBIT_GATEWAY_IMAGE:-ghcr.io/hardimpactdev/orbit-gateway@sha256:digest}',
                'docker service update --image "${ORBIT_GATEWAY_IMAGE}" orbit-gateway',
            ];

            if (in_array('router', $roles, true)) {
                $lines = [
                    ...$lines,
                    'ORBIT_CADDY_IMAGE=${ORBIT_CADDY_IMAGE:-orbit-caddy}',
                    'docker service update --image "${ORBIT_CADDY_IMAGE}" orbit-caddy',
                    '@wireguard remote_ip 10.6.0.0/24',
                    'handle @wireguard {',
                    '    reverse_proxy http://orbit-gateway:8080',
                    '}',
                ];
            } else {
                $lines[] = 'published: 443';
            }
        }

        if (self::artifactProdRequiresHostPhp($roles)) {
            $lines[] = 'apt-get install -y php8.5-cli composer';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private static function normalizeRoles(array $roles): array
    {
        return array_values(array_unique(array_map(
            fn (string $role): string => match (strtolower(trim($role))) {
                'dev' => 'app-dev',
                'prod' => 'app-prod',
                default => strtolower(trim($role)),
            },
            $roles,
        )));
    }
}
