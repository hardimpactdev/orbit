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
        return $this->availabilityFor($kind)['host'];
    }

    /**
     * @return array{host: IncusHost|null, reason: string|null}
     */
    public function availabilityFor(E2ETopologyKind $kind): array
    {
        $requiredSlots = count(IncusTopologyTemplate::rolesFor($kind));
        $reasons = [];

        foreach ($this->hosts as $host) {
            $hostName = $host->config->host;

            if (! IncusTopologyTemplate::availableOn($host, $kind)) {
                $reasons[] = "{$hostName} is missing prepared templates or snapshots";

                continue;
            }

            $running = $host->runningE2EInstanceCount();
            $freeSlots = $host->config->incusMaxVmsPerHost - $running;

            if ($freeSlots >= $requiredSlots) {
                return [
                    'host' => $host,
                    'reason' => null,
                ];
            }

            $reasons[] = "{$hostName} has {$freeSlots}/{$requiredSlots} free VM slots ({$running}/{$host->config->incusMaxVmsPerHost} Orbit E2E VMs running)";
        }

        return [
            'host' => null,
            'reason' => $reasons === [] ? 'no Incus hosts configured' : implode('; ', $reasons),
        ];
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
