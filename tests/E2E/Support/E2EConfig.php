<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2EConfig
{
    public function __construct(
        /** @var list<string> */
        public array $providerNames,
        /** @var list<string> */
        public array $topologyProviderNames,
        public string $host,
        public string $sourceImage,
        public string $blankImage,
        public string $controlImage,
        public string $gatewayImage,
        public string $hcloudServerType,
        public string $hcloudLocation,
        public string $hcloudBlankImage,
        public string $hcloudControlImage,
        public string $hcloudGatewayImage,
        public string $bootstrapUser,
        public string $controlUser,
        public string $instancePrefix,
        public int $timeoutSeconds,
        public string $cpus,
        public string $memory,
        public string $topologyCpus,
        public string $topologyMemory,
        public int $incusMaxVmsPerHost,
        /** @var list<string> */
        public array $dockerHosts,
        public int $dockerMaxContainersPerHost,
        public bool $keep,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            providerNames: self::providerNames(),
            topologyProviderNames: self::topologyProviderNames(),
            host: self::envString('ORBIT_E2E_HOST', 'beast'),
            sourceImage: self::envString('ORBIT_E2E_SOURCE_IMAGE', self::envString('ORBIT_E2E_IMAGE', 'images:ubuntu/26.04/cloud')),
            blankImage: self::envString('ORBIT_E2E_BLANK_IMAGE', 'orbit-blank-ubuntu-26.04'),
            controlImage: self::envString('ORBIT_E2E_CONTROL_IMAGE', 'orbit-ready-control'),
            gatewayImage: self::envString('ORBIT_E2E_GATEWAY_IMAGE', 'orbit-ready-gateway'),
            hcloudServerType: self::envString('ORBIT_E2E_HCLOUD_SERVER_TYPE', 'cpx11'),
            hcloudLocation: self::envString('ORBIT_E2E_HCLOUD_LOCATION', 'ash'),
            hcloudBlankImage: self::envString('ORBIT_E2E_HCLOUD_BLANK_IMAGE', 'ubuntu-24.04'),
            hcloudControlImage: self::envString('ORBIT_E2E_HCLOUD_CONTROL_IMAGE', ''),
            hcloudGatewayImage: self::envString('ORBIT_E2E_HCLOUD_GATEWAY_IMAGE', ''),
            bootstrapUser: self::envString('ORBIT_E2E_BOOTSTRAP_USER', 'provisioner'),
            controlUser: self::envString('ORBIT_E2E_CONTROL_USER', 'control'),
            instancePrefix: self::envString('ORBIT_E2E_INSTANCE_PREFIX', 'orbit-e2e'),
            timeoutSeconds: self::envInt('ORBIT_E2E_TIMEOUT_SECONDS', 600),
            cpus: self::envString('ORBIT_E2E_CPUS', '2'),
            memory: self::envString('ORBIT_E2E_MEMORY', '2GiB'),
            topologyCpus: self::envString('ORBIT_E2E_TOPOLOGY_CPUS', '1'),
            topologyMemory: self::envString('ORBIT_E2E_TOPOLOGY_MEMORY', '2GiB'),
            incusMaxVmsPerHost: self::envInt('ORBIT_E2E_INCUS_MAX_VMS_PER_HOST', 4),
            dockerHosts: self::parseProviderNames(self::envString('ORBIT_E2E_DOCKER_HOSTS', 'local')),
            dockerMaxContainersPerHost: self::envInt('ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST', 8),
            keep: self::envString('ORBIT_E2E_KEEP', '0') === '1',
        );
    }

    /**
     * @return list<string>
     */
    private static function topologyProviderNames(): array
    {
        $providers = self::envString('ORBIT_E2E_TOPOLOGY_PROVIDERS', '');

        if ($providers !== '') {
            $names = self::parseProviderNames($providers);

            return in_array('auto', $names, true) ? ['incus'] : $names;
        }

        $provider = self::envString('ORBIT_E2E_TOPOLOGY_PROVIDER', '');

        if ($provider !== '') {
            if ($provider === 'auto') {
                return ['incus'];
            }

            return self::parseProviderNames($provider);
        }

        return ['incus'];
    }

    /**
     * @return list<string>
     */
    private static function providerNames(): array
    {
        $providers = self::envString('ORBIT_E2E_PROVIDERS', '');

        if ($providers !== '') {
            return self::parseProviderNames($providers);
        }

        $provider = self::envString('ORBIT_E2E_PROVIDER', 'incus');

        if ($provider === 'auto') {
            return ['incus', 'hcloud'];
        }

        return self::parseProviderNames($provider);
    }

    /**
     * @return list<string>
     */
    private static function parseProviderNames(string $providers): array
    {
        $names = array_values(array_filter(
            array_map(
                fn (string $provider): string => strtolower(trim($provider)),
                explode(',', $providers),
            ),
            fn (string $provider): bool => $provider !== '',
        ));

        return $names !== [] ? $names : ['incus'];
    }

    private static function envString(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function envInt(string $key, int $default): int
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    public function forHost(string $host): self
    {
        return new self(
            providerNames: $this->providerNames,
            topologyProviderNames: $this->topologyProviderNames,
            host: $host,
            sourceImage: $this->sourceImage,
            blankImage: $this->blankImage,
            controlImage: $this->controlImage,
            gatewayImage: $this->gatewayImage,
            hcloudServerType: $this->hcloudServerType,
            hcloudLocation: $this->hcloudLocation,
            hcloudBlankImage: $this->hcloudBlankImage,
            hcloudControlImage: $this->hcloudControlImage,
            hcloudGatewayImage: $this->hcloudGatewayImage,
            bootstrapUser: $this->bootstrapUser,
            controlUser: $this->controlUser,
            instancePrefix: $this->instancePrefix,
            timeoutSeconds: $this->timeoutSeconds,
            cpus: $this->cpus,
            memory: $this->memory,
            topologyCpus: $this->topologyCpus,
            topologyMemory: $this->topologyMemory,
            incusMaxVmsPerHost: $this->incusMaxVmsPerHost,
            dockerHosts: $this->dockerHosts,
            dockerMaxContainersPerHost: $this->dockerMaxContainersPerHost,
            keep: $this->keep,
        );
    }
}
