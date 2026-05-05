<?php

declare(strict_types=1);

namespace App\E2E\Support;

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
        public string $baseImage,
        public string $hcloudServerType,
        public string $hcloudLocation,
        public string $hcloudBlankImage,
        public string $bootstrapUser,
        public string $controlUser,
        public string $instancePrefix,
        public int $timeoutSeconds,
        public string $cpus,
        public string $memory,
        public string $topologyCpus,
        public string $topologyMemory,
        public string $topologyStateSize,
        public string $incusStoragePool,
        public int $incusMaxVmsPerHost,
        /** @var list<string> */
        public array $dockerHosts,
        public int $dockerMaxContainersPerHost,
        public bool $keep,
        /** @var array<string, int> */
        public array $incusHostSlots = [],
        /** @var array<string, int> */
        public array $hcloudLocationSlots = [],
        /** @var array<string, int> */
        public array $hcloudResourceSlots = [],
        public int $slotWaitSeconds = 900,
        public int $slotStaleSeconds = 7200,
        /** @var array<string, int> */
        public array $dockerHostSlots = [],
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            providerNames: self::providerNames(),
            topologyProviderNames: self::topologyProviderNames(),
            host: self::envString('ORBIT_E2E_HOST', 'beast'),
            sourceImage: self::envString('ORBIT_E2E_SOURCE_IMAGE', self::envString('ORBIT_E2E_IMAGE', 'images:ubuntu/26.04/cloud')),
            blankImage: self::envString('ORBIT_E2E_BLANK_IMAGE', 'orbit-blank-ubuntu-26.04'),
            baseImage: self::envString('ORBIT_E2E_BASE_IMAGE', 'orbit-base-ubuntu-26.04'),
            hcloudServerType: self::envString('ORBIT_E2E_HCLOUD_SERVER_TYPE', 'cpx11'),
            hcloudLocation: self::envString('ORBIT_E2E_HCLOUD_LOCATION', 'ash'),
            hcloudBlankImage: self::envString('ORBIT_E2E_HCLOUD_BLANK_IMAGE', 'ubuntu-24.04'),
            bootstrapUser: self::envString('ORBIT_E2E_BOOTSTRAP_USER', 'provisioner'),
            controlUser: self::envString('ORBIT_E2E_CONTROL_USER', 'control'),
            instancePrefix: self::envString('ORBIT_E2E_INSTANCE_PREFIX', 'orbit-e2e'),
            timeoutSeconds: self::envInt('ORBIT_E2E_TIMEOUT_SECONDS', 600),
            cpus: self::envString('ORBIT_E2E_CPUS', '2'),
            memory: self::envString('ORBIT_E2E_MEMORY', '2GiB'),
            topologyCpus: self::envString('ORBIT_E2E_TOPOLOGY_CPUS', '1'),
            topologyMemory: self::envString('ORBIT_E2E_TOPOLOGY_MEMORY', '2GiB'),
            topologyStateSize: self::envString('ORBIT_E2E_TOPOLOGY_STATE_SIZE', '4GiB'),
            incusStoragePool: self::envString('ORBIT_E2E_INCUS_STORAGE_POOL', ''),
            incusMaxVmsPerHost: self::envInt('ORBIT_E2E_INCUS_MAX_VMS_PER_HOST', 4),
            incusHostSlots: self::parseHostSlots(self::envString('ORBIT_E2E_INCUS_HOST_SLOTS', ''), backend: 'Incus'),
            hcloudLocationSlots: self::parseHostSlots(self::envString('ORBIT_E2E_HCLOUD_LOCATION_SLOTS', ''), backend: 'Hcloud'),
            hcloudResourceSlots: self::parseHostSlots(self::envString('ORBIT_E2E_HCLOUD_RESOURCE_SLOTS', ''), backend: 'Hcloud'),
            dockerHosts: self::parseProviderNames(self::envString('ORBIT_E2E_DOCKER_HOSTS', 'local')),
            dockerMaxContainersPerHost: self::envInt('ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST', 8),
            keep: self::envString('ORBIT_E2E_KEEP', '0') === '1',
            slotWaitSeconds: self::envInt('ORBIT_E2E_SLOT_WAIT_SECONDS', 900),
            slotStaleSeconds: self::envInt('ORBIT_E2E_SLOT_STALE_SECONDS', 7200),
            dockerHostSlots: self::parseHostSlots(self::envString('ORBIT_E2E_DOCKER_HOST_SLOTS', ''), backend: 'Docker'),
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
            return ['incus'];
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

    /**
     * @return array<string, int>
     */
    private static function parseHostSlots(string $slots, string $backend): array
    {
        $entries = array_values(array_filter(
            array_map(trim(...), explode(',', $slots)),
            fn (string $entry): bool => $entry !== '',
        ));

        $hostSlots = [];

        foreach ($entries as $entry) {
            [$host, $slotCount] = array_pad(array_map(trim(...), explode(':', $entry, 2)), 2, '');
            $host = strtolower($host);

            if ($host === '' || $slotCount === '') {
                throw new \InvalidArgumentException("Invalid {$backend} host slot entry [{$entry}]. Expected host:slots.");
            }

            $slots = (int) $slotCount;

            if ((string) $slots !== $slotCount || $slots < 1) {
                throw new \InvalidArgumentException("Invalid {$backend} host slot count [{$slotCount}] for host [{$host}].");
            }

            $hostSlots[$host] = $slots;
        }

        return $hostSlots;
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
            baseImage: $this->baseImage,
            hcloudServerType: $this->hcloudServerType,
            hcloudLocation: $this->hcloudLocation,
            hcloudBlankImage: $this->hcloudBlankImage,
            bootstrapUser: $this->bootstrapUser,
            controlUser: $this->controlUser,
            instancePrefix: $this->instancePrefix,
            timeoutSeconds: $this->timeoutSeconds,
            cpus: $this->cpus,
            memory: $this->memory,
            topologyCpus: $this->topologyCpus,
            topologyMemory: $this->topologyMemory,
            topologyStateSize: $this->topologyStateSize,
            incusStoragePool: $this->incusStoragePool,
            incusMaxVmsPerHost: $this->incusMaxVmsPerHost,
            incusHostSlots: $this->incusHostSlots,
            hcloudLocationSlots: $this->hcloudLocationSlots,
            hcloudResourceSlots: $this->hcloudResourceSlots,
            dockerHosts: $this->dockerHosts,
            dockerMaxContainersPerHost: $this->dockerMaxContainersPerHost,
            keep: $this->keep,
            slotWaitSeconds: $this->slotWaitSeconds,
            slotStaleSeconds: $this->slotStaleSeconds,
            dockerHostSlots: $this->dockerHostSlots,
        );
    }

    public function forHcloudLocation(string $location): self
    {
        return new self(
            providerNames: $this->providerNames,
            topologyProviderNames: $this->topologyProviderNames,
            host: $this->host,
            sourceImage: $this->sourceImage,
            blankImage: $this->blankImage,
            baseImage: $this->baseImage,
            hcloudServerType: $this->hcloudServerType,
            hcloudLocation: $location,
            hcloudBlankImage: $this->hcloudBlankImage,
            bootstrapUser: $this->bootstrapUser,
            controlUser: $this->controlUser,
            instancePrefix: $this->instancePrefix,
            timeoutSeconds: $this->timeoutSeconds,
            cpus: $this->cpus,
            memory: $this->memory,
            topologyCpus: $this->topologyCpus,
            topologyMemory: $this->topologyMemory,
            topologyStateSize: $this->topologyStateSize,
            incusStoragePool: $this->incusStoragePool,
            incusMaxVmsPerHost: $this->incusMaxVmsPerHost,
            incusHostSlots: $this->incusHostSlots,
            hcloudLocationSlots: $this->hcloudLocationSlots,
            hcloudResourceSlots: $this->hcloudResourceSlots,
            dockerHosts: $this->dockerHosts,
            dockerMaxContainersPerHost: $this->dockerMaxContainersPerHost,
            keep: $this->keep,
            slotWaitSeconds: $this->slotWaitSeconds,
            slotStaleSeconds: $this->slotStaleSeconds,
            dockerHostSlots: $this->dockerHostSlots,
        );
    }

    public function forHcloudResource(string $resource): self
    {
        [$location, $serverType, $image] = array_pad(explode('/', $resource, 3), 3, '');

        if ($location === '' || $serverType === '' || $image === '') {
            throw new \InvalidArgumentException("Invalid Hcloud resource slot [{$resource}]. Expected location/server-type/image.");
        }

        return new self(
            providerNames: $this->providerNames,
            topologyProviderNames: $this->topologyProviderNames,
            host: $this->host,
            sourceImage: $this->sourceImage,
            blankImage: $this->blankImage,
            baseImage: $this->baseImage,
            hcloudServerType: $serverType,
            hcloudLocation: $location,
            hcloudBlankImage: $image,
            bootstrapUser: $this->bootstrapUser,
            controlUser: $this->controlUser,
            instancePrefix: $this->instancePrefix,
            timeoutSeconds: $this->timeoutSeconds,
            cpus: $this->cpus,
            memory: $this->memory,
            topologyCpus: $this->topologyCpus,
            topologyMemory: $this->topologyMemory,
            topologyStateSize: $this->topologyStateSize,
            incusStoragePool: $this->incusStoragePool,
            incusMaxVmsPerHost: $this->incusMaxVmsPerHost,
            incusHostSlots: $this->incusHostSlots,
            hcloudLocationSlots: $this->hcloudLocationSlots,
            hcloudResourceSlots: $this->hcloudResourceSlots,
            dockerHosts: $this->dockerHosts,
            dockerMaxContainersPerHost: $this->dockerMaxContainersPerHost,
            keep: $this->keep,
            slotWaitSeconds: $this->slotWaitSeconds,
            slotStaleSeconds: $this->slotStaleSeconds,
            dockerHostSlots: $this->dockerHostSlots,
        );
    }
}
