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
        $hosts = [];
        foreach ($config->incusHostCandidates() as $host) {
            $hosts[] = new IncusHost($config->forHost($host));
        }

        return new self($hosts);
    }

    public function firstAvailableFor(E2ETopologyKind $kind): ?IncusHost
    {
        return $this->availabilityFor($kind)['host'];
    }

    /**
     * @param  list<string>|null  $hostNames
     * @return array{host: IncusHost|null, reason: string|null}
     */
    public function availabilityFor(E2ETopologyKind $kind, ?array $hostNames = null, bool $checkCapacity = true): array
    {
        $requiredSlots = count(IncusTopologyTemplate::rolesFor($kind));
        $hostLookup = $hostNames === null ? null : array_fill_keys($hostNames, true);
        $reasons = [];

        foreach ($this->hosts as $host) {
            $hostName = $host->config->host;

            if ($hostLookup !== null && ! isset($hostLookup[$hostName])) {
                continue;
            }

            if (! IncusTopologyTemplate::availableOn($host, $kind)) {
                $reasons[] = "{$hostName} is missing prepared templates or snapshots";

                continue;
            }

            if (! $checkCapacity) {
                return [
                    'host' => $host,
                    'reason' => null,
                ];
            }

            try {
                $hostCap = $host->config->incusMaxVmsForHost($hostName);
            } catch (\InvalidArgumentException $exception) {
                $reasons[] = "{$hostName}: {$exception->getMessage()}";

                continue;
            }

            $running = $host->runningE2EInstanceCount();
            $freeSlots = $hostCap - $running;

            if ($freeSlots >= $requiredSlots) {
                return [
                    'host' => $host,
                    'reason' => null,
                ];
            }

            $reasons[] = "{$hostName} has {$freeSlots}/{$requiredSlots} free VM slots ({$running}/{$hostCap} Orbit E2E VMs running)";
        }

        return [
            'host' => null,
            'reason' => $reasons === [] ? $this->emptyReason($hostNames) : implode('; ', $reasons),
        ];
    }

    /**
     * @param  list<string>|null  $hostNames
     */
    private function emptyReason(?array $hostNames): string
    {
        if ($hostNames === null) {
            return 'no Incus hosts configured';
        }

        return 'no matching Incus hosts configured for '.implode(', ', $hostNames);
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
