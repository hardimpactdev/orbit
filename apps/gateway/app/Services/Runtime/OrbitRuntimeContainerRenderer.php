<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use InvalidArgumentException;

class OrbitRuntimeContainerRenderer
{
    public function __construct(
        private readonly OrbitContainerNames $names,
    ) {}

    /**
     * @param  array<string, scalar|null>  $environment
     */
    public function render(
        string $orbitCheckoutPath,
        ?string $gatewayDatabasePath = null,
        string $image = 'orbit-runtime:current',
        array $environment = [],
    ): OrbitRuntimeContainer {
        $resolvedEnvironment = $this->stringEnvironment($environment);
        $resolvedEnvironment['ORBIT_SOURCE_PATH'] = OrbitRuntimeContainer::SourcePath;

        if ($gatewayDatabasePath !== null) {
            $resolvedEnvironment['ORBIT_IS_GATEWAY'] = '1';
            $resolvedEnvironment['ORBIT_TRUST_WIREGUARD_PROXY_HEADER'] = '1';
        }

        $mounts = [
            [
                'source' => $this->normalizePath($orbitCheckoutPath, 'orbitCheckoutPath'),
                'target' => OrbitRuntimeContainer::SourcePath,
                'read_only' => false,
            ],
            [
                'source' => '/var/run/docker.sock',
                'target' => '/var/run/docker.sock',
                'read_only' => false,
            ],
        ];

        if ($gatewayDatabasePath !== null) {
            $mounts[] = [
                'source' => $this->normalizePath($gatewayDatabasePath, 'gatewayDatabasePath'),
                'target' => OrbitRuntimeContainer::SourcePath.'/apps/gateway/database/database.sqlite',
                'read_only' => false,
            ];
        }

        return new OrbitRuntimeContainer(
            name: $this->names->runtime(),
            image: $image,
            network: $this->names->network(),
            restartPolicy: 'unless-stopped',
            environment: $resolvedEnvironment,
            mounts: $mounts,
            networkAliases: [$this->names->runtime()],
        );
    }

    /**
     * @param  array<string, scalar|null>  $environment
     * @return array<string, string>
     */
    private function stringEnvironment(array $environment): array
    {
        $resolved = [];

        foreach ($environment as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Runtime container environment keys must be non-empty strings.');
            }

            $resolved[$key] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                $value === null => '',
                default => (string) $value,
            };
        }

        return $resolved;
    }

    private function normalizePath(string $path, string $field): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException("Runtime container {$field} cannot be empty.");
        }

        if ($path === '/') {
            return $path;
        }

        return rtrim($path, '/');
    }
}
