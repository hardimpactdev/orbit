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
        public string $baseImage,
        public string $bootstrapUser,
        public string $controlUser,
        public string $instancePrefix,
        public int $timeoutSeconds,
        public string $cpus,
        public string $memory,
        public string $topologyCpus,
        public string $topologyMemory,
        public string $topologyRootSize,
        public string $topologyStateSize,
        public string $incusStoragePool,
        /** @var list<string> */
        public array $dockerHosts,
        public bool $keep,
        /** @var array<string, int> */
        public array $incusHostSlots = [],
        public int $slotWaitSeconds = 900,
        public int $slotStaleSeconds = 7200,
        /** @var array<string, int> */
        public array $dockerHostSlots = [],
        public string $incusImageBuildHost = '',
        /** @var list<string> */
        public array $dockerImageBuildHosts = [],
        /** @var list<string> */
        public array $exclusiveHosts = [],
        public string $operatorUser = 'orbit',
        /** @var array<string, int> */
        public array $dockerHostContainerCaps = [],
        /** @var list<string> */
        public array $incusHosts = [],
        /** @var array<string, int> */
        public array $incusHostVmCaps = [],
    ) {}

    public static function fromEnvironment(): self
    {
        $host = self::envString('ORBIT_E2E_HOST', 'beast');
        $dockerTestRunners = self::parseDockerTestRunners(self::envString('ORBIT_E2E_DOCKER_TEST_RUNNERS', ''));

        return new self(
            providerNames: self::providerNames(),
            topologyProviderNames: self::topologyProviderNames(),
            host: $host,
            sourceImage: self::envString('ORBIT_E2E_SOURCE_IMAGE', self::envString('ORBIT_E2E_IMAGE', 'images:ubuntu/26.04/cloud')),
            baseImage: self::envString('ORBIT_E2E_BASE_IMAGE', 'orbit-base-ubuntu-26.04'),
            bootstrapUser: self::envString('ORBIT_E2E_BOOTSTRAP_USER', 'provisioner'),
            operatorUser: self::envString('ORBIT_E2E_OPERATOR_USER', self::envString('ORBIT_E2E_CONTROL_USER', 'orbit')),
            controlUser: self::envString('ORBIT_E2E_CONTROL_USER', self::envString('ORBIT_E2E_OPERATOR_USER', 'orbit')),
            instancePrefix: self::envString('ORBIT_E2E_INSTANCE_PREFIX', 'orbit-e2e'),
            timeoutSeconds: self::envInt('ORBIT_E2E_TIMEOUT_SECONDS', 600),
            cpus: self::envString('ORBIT_E2E_CPUS', '2'),
            memory: self::envString('ORBIT_E2E_MEMORY', '2GiB'),
            topologyCpus: self::envString('ORBIT_E2E_TOPOLOGY_CPUS', '1'),
            topologyMemory: self::envString('ORBIT_E2E_TOPOLOGY_MEMORY', '2GiB'),
            topologyRootSize: self::envString('ORBIT_E2E_TOPOLOGY_ROOT_SIZE', '16GiB'),
            topologyStateSize: self::envString('ORBIT_E2E_TOPOLOGY_STATE_SIZE', '4GiB'),
            incusStoragePool: self::envString('ORBIT_E2E_INCUS_STORAGE_POOL', ''),
            incusHostSlots: self::parseHostSlots(self::envString('ORBIT_E2E_INCUS_HOST_SLOTS', ''), backend: 'Incus'),
            dockerHosts: $dockerTestRunners['hosts'],
            keep: self::envString('ORBIT_E2E_KEEP', '0') === '1',
            slotWaitSeconds: self::envInt('ORBIT_E2E_SLOT_WAIT_SECONDS', 900),
            slotStaleSeconds: self::envInt('ORBIT_E2E_SLOT_STALE_SECONDS', 7200),
            dockerHostSlots: $dockerTestRunners['slots'],
            incusImageBuildHost: self::envString('ORBIT_E2E_INCUS_IMAGE_BUILD_HOST', $host),
            dockerImageBuildHosts: self::parseOptionalNames(self::envString('ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS', '')),
            exclusiveHosts: self::parseOptionalNames(self::envString('ORBIT_E2E_EXCLUSIVE_HOSTS', '')),
            dockerHostContainerCaps: $dockerTestRunners['container_caps'],
            incusHosts: self::parseOptionalNames(self::envString('ORBIT_E2E_INCUS_HOSTS', '')),
            incusHostVmCaps: self::parseHostVmCaps(self::envString('ORBIT_E2E_INCUS_HOST_VM_CAPS', '')),
        );
    }

    public function dockerMaxContainersForHost(string $host): int
    {
        $host = strtolower($host);

        return $this->dockerHostContainerCaps[$host]
            ?? throw new \InvalidArgumentException("Missing Docker container cap for host [{$host}]. Set ORBIT_E2E_DOCKER_TEST_RUNNERS.");
    }

    public function incusMaxVmsForHost(string $host): int
    {
        $host = strtolower($host);

        return $this->incusHostVmCaps[$host]
            ?? throw new \InvalidArgumentException("Missing Incus VM cap for host [{$host}]. Set ORBIT_E2E_INCUS_HOST_VM_CAPS.");
    }

    /**
     * @return list<string>
     */
    public function incusHostCandidates(): array
    {
        if ($this->incusHosts !== []) {
            return $this->incusHosts;
        }

        if ($this->incusHostSlots !== []) {
            return array_keys($this->incusHostSlots);
        }

        return [$this->host];
    }

    /**
     * @return list<string>
     */
    private static function topologyProviderNames(): array
    {
        $providers = self::envString('ORBIT_E2E_TOPOLOGY_PROVIDERS', '');

        if ($providers !== '') {
            $names = self::parseProviderNames($providers, supported: ['docker', 'incus']);

            return in_array('auto', $names, true) ? ['incus'] : $names;
        }

        $provider = self::envString('ORBIT_E2E_TOPOLOGY_PROVIDER', '');

        if ($provider !== '') {
            if ($provider === 'auto') {
                return ['incus'];
            }

            return self::parseProviderNames($provider, supported: ['docker', 'incus']);
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
            return self::parseProviderNames($providers, supported: ['incus']);
        }

        $provider = self::envString('ORBIT_E2E_PROVIDER', 'incus');

        if ($provider === 'auto') {
            return ['incus'];
        }

        return self::parseProviderNames($provider, supported: ['incus']);
    }

    /**
     * @param  list<string>  $supported
     * @return list<string>
     */
    private static function parseProviderNames(string $providers, array $supported): array
    {
        $names = array_values(array_filter(
            array_map(
                fn (string $provider): string => strtolower(trim($provider)),
                explode(',', $providers),
            ),
            fn (string $provider): bool => $provider !== '',
        ));

        $names = $names !== [] ? $names : ['incus'];

        foreach ($names as $name) {
            if ($name === 'auto' || in_array($name, $supported, true)) {
                continue;
            }

            throw new \InvalidArgumentException(
                'Unsupported E2E provider ['.$name.']. Supported providers: '.implode(', ', $supported).'.'
            );
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function parseOptionalNames(string $names): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $name): string => strtolower(trim($name)),
                explode(',', $names),
            ),
            fn (string $name): bool => $name !== '',
        ));
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

    /**
     * @return array{hosts: list<string>, slots: array<string, int>, container_caps: array<string, int>}
     */
    private static function parseDockerTestRunners(string $runners): array
    {
        $entries = array_values(array_filter(
            array_map(trim(...), explode(',', $runners)),
            fn (string $entry): bool => $entry !== '',
        ));

        if ($entries === []) {
            return [
                'hosts' => ['local'],
                'slots' => [],
                'container_caps' => [],
            ];
        }

        $hosts = [];
        $hostSlots = [];
        $hostContainerCaps = [];

        foreach ($entries as $entry) {
            [$host, $slotCount, $cap] = array_pad(array_map(trim(...), explode(':', $entry, 3)), 3, '');
            $host = strtolower($host);

            if ($host === '' || $slotCount === '' || $cap === '') {
                throw new \InvalidArgumentException("Invalid Docker test runner entry [{$entry}]. Expected host:slots:containers.");
            }

            $slots = (int) $slotCount;

            if ((string) $slots !== $slotCount || $slots < 1) {
                throw new \InvalidArgumentException("Invalid Docker test runner slot count [{$slotCount}] for host [{$host}].");
            }

            $containers = (int) $cap;

            if ((string) $containers !== $cap || $containers < 1) {
                throw new \InvalidArgumentException("Invalid Docker test runner container cap [{$cap}] for host [{$host}].");
            }

            $hosts[] = $host;
            $hostSlots[$host] = $slots;
            $hostContainerCaps[$host] = $containers;
        }

        return [
            'hosts' => array_values(array_unique($hosts)),
            'slots' => $hostSlots,
            'container_caps' => $hostContainerCaps,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function parseHostVmCaps(string $caps): array
    {
        $entries = array_values(array_filter(
            array_map(trim(...), explode(',', $caps)),
            fn (string $entry): bool => $entry !== '',
        ));

        $hostCaps = [];

        foreach ($entries as $entry) {
            [$host, $cap] = array_pad(array_map(trim(...), explode(':', $entry, 2)), 2, '');
            $host = strtolower($host);

            if ($host === '' || $cap === '') {
                throw new \InvalidArgumentException("Invalid Incus host VM cap entry [{$entry}]. Expected host:vms.");
            }

            $vms = (int) $cap;

            if ((string) $vms !== $cap || $vms < 1) {
                throw new \InvalidArgumentException("Invalid Incus host VM cap [{$cap}] for host [{$host}].");
            }

            $hostCaps[$host] = $vms;
        }

        return $hostCaps;
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
            baseImage: $this->baseImage,
            bootstrapUser: $this->bootstrapUser,
            operatorUser: $this->operatorUser,
            controlUser: $this->controlUser,
            instancePrefix: $this->instancePrefix,
            timeoutSeconds: $this->timeoutSeconds,
            cpus: $this->cpus,
            memory: $this->memory,
            topologyCpus: $this->topologyCpus,
            topologyMemory: $this->topologyMemory,
            topologyRootSize: $this->topologyRootSize,
            topologyStateSize: $this->topologyStateSize,
            incusStoragePool: $this->incusStoragePool,
            incusHostSlots: $this->incusHostSlots,
            dockerHosts: $this->dockerHosts,
            keep: $this->keep,
            slotWaitSeconds: $this->slotWaitSeconds,
            slotStaleSeconds: $this->slotStaleSeconds,
            dockerHostSlots: $this->dockerHostSlots,
            incusImageBuildHost: $this->incusImageBuildHost,
            dockerImageBuildHosts: $this->dockerImageBuildHosts,
            exclusiveHosts: $this->exclusiveHosts,
            dockerHostContainerCaps: $this->dockerHostContainerCaps,
            incusHosts: $this->incusHosts,
            incusHostVmCaps: $this->incusHostVmCaps,
        );
    }
}
