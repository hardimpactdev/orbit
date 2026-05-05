<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class IncusHostPool
{
    /**
     * @param  list<IncusHost>  $hosts
     */
    public function __construct(private array $hosts) {}

    public static function fromEnvironment(E2EConfig $config): self
    {
        $hostsEnv = getenv('ORBIT_E2E_INCUS_HOSTS');

        if (! is_string($hostsEnv) || $hostsEnv === '') {
            return new self([new IncusHost($config)]);
        }

        $hosts = [];
        foreach (array_filter(array_map(trim(...), explode(',', $hostsEnv))) as $host) {
            $hosts[] = new IncusHost($config->forHost($host));
        }

        return new self($hosts);
    }

    public function firstAvailableFor(E2ETopologyKind $kind): ?IncusHost
    {
        $requiredSlots = count(IncusTopologyTemplate::rolesFor($kind));

        foreach ($this->hosts as $host) {
            if (! IncusTopologyTemplate::availableOn($host, $kind)) {
                continue;
            }

            $freeSlots = $host->config->incusMaxVmsPerHost - $host->runningE2EInstanceCount();

            if ($freeSlots >= $requiredSlots) {
                return $host;
            }
        }

        return null;
    }

    public function first(): ?IncusHost
    {
        return $this->hosts[0] ?? null;
    }

    /**
     * @return list<IncusHost>
     */
    public function hosts(): array
    {
        return $this->hosts;
    }
}
