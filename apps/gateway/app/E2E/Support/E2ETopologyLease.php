<?php

declare(strict_types=1);

namespace App\E2E\Support;

final class E2ETopologyLease
{
    private bool $cleaned = false;

    private bool $finalized = false;

    /**
     * @param  \Closure(E2EPhaseTimer): array{
     *     instances: array<string, E2EInstance>,
     *     snapshotReset: (\Closure(E2EPhaseTimer): void)|null
     * }  $rebuild
     * @param  (\Closure(E2EPhaseTimer): void)|null  $snapshotReset
     * @param  (\Closure(E2EPhaseTimer): void)|null  $bulkCleanup
     * @param  (\Closure(E2EPhaseTimer): void)|null  $teardown
     * @param  array<string, E2EInstance>  $additionalInstances
     */
    public function __construct(
        private readonly E2ETopologyKind $kind,
        private E2EInstance $control,
        private ?E2EInstance $gateway,
        private ?E2EInstance $dev,
        private ?E2EInstance $prod,
        private readonly SshKeyPair $sshKeyPair,
        private readonly \Closure $rebuild,
        private ?\Closure $snapshotReset = null,
        private readonly ?\Closure $bulkCleanup = null,
        private readonly ?\Closure $teardown = null,
        private readonly string $gatewayApiIp = '10.6.0.2',
        private readonly ?E2EResourceLease $resourceLease = null,
        private ?E2EInstance $agent = null,
        private ?E2EInstance $ingress = null,
        private array $additionalInstances = [],
    ) {}

    public function kind(): E2ETopologyKind
    {
        return $this->kind;
    }

    public function control(): E2EInstance
    {
        return $this->control;
    }

    public function operator(): E2EInstance
    {
        return $this->control();
    }

    public function gateway(): ?E2EInstance
    {
        return $this->gateway;
    }

    public function devApp(): ?E2EInstance
    {
        return $this->dev;
    }

    public function prodApp(): ?E2EInstance
    {
        return $this->prod;
    }

    public function agent(): ?E2EInstance
    {
        return $this->agent;
    }

    public function ingress(): ?E2EInstance
    {
        return $this->ingress;
    }

    public function instance(string $role): ?E2EInstance
    {
        return match ($role) {
            'operator' => $this->control,
            'control' => $this->control,
            'gateway' => $this->gateway,
            'dev' => $this->dev,
            'prod' => $this->prod,
            'agent' => $this->agent,
            'ingress' => $this->ingress,
            default => $this->additionalInstances[$role] ?? null,
        };
    }

    public function sshKeyPair(): SshKeyPair
    {
        return $this->sshKeyPair;
    }

    public function gatewayApiIp(): string
    {
        return $this->gatewayApiIp;
    }

    public function cleanup(?E2EPhaseTimer $timer = null, bool $releaseResourceLease = true): void
    {
        if ($this->cleaned && (! $releaseResourceLease || $this->finalized)) {
            return;
        }

        $shouldFlush = $timer === null;
        $timer ??= new E2EPhaseTimer;

        try {
            if (! $this->cleaned) {
                if ($this->bulkCleanup !== null) {
                    ($this->bulkCleanup)($timer);
                } else {
                    foreach ($this->allInstances() as $role => $instance) {
                        if ($instance !== null) {
                            $timer->measure("cleanup.{$role}", fn () => $instance->delete());
                        }
                    }
                }

                $this->cleaned = true;
            }

            if ($releaseResourceLease && ! $this->finalized) {
                if ($this->teardown !== null) {
                    ($this->teardown)($timer);
                }

                $this->resourceLease?->release();
                $this->finalized = true;
            }
        } finally {
            if ($shouldFlush) {
                $timer->flush('cleanup');
            }
        }
    }

    public function reset(): void
    {
        $resetMode = getenv('ORBIT_E2E_TOPOLOGY_RESET');
        $resetMode = is_string($resetMode) && $resetMode !== '' ? $resetMode : 'fresh-clone';

        $timer = new E2EPhaseTimer;

        try {
            if (in_array($resetMode, ['snapshot-restore', 'stateful-restore'], true) && $this->snapshotReset !== null) {
                ($this->snapshotReset)($timer);
                $this->cleaned = false;

                return;
            }

            $this->cleanup($timer, releaseResourceLease: false);
            $payload = ($this->rebuild)($timer);
            $instances = $payload['instances'];

            $this->control = $instances['operator'] ?? $instances['control'];
            $this->gateway = $instances['gateway'] ?? null;
            $this->dev = $instances['dev'] ?? null;
            $this->prod = $instances['prod'] ?? null;
            $this->agent = $instances['agent'] ?? null;
            $this->ingress = $instances['ingress'] ?? null;
            $this->additionalInstances = $this->additionalInstancesFrom($instances);
            $this->snapshotReset = $payload['snapshotReset'];
            $this->cleaned = false;
            $this->finalized = false;
        } finally {
            $timer->flush('reset');
        }
    }

    /**
     * @return list<string>
     */
    public function instanceNames(): array
    {
        return array_values(array_unique(array_filter([
            $this->control->name(),
            $this->gateway?->name(),
            $this->dev?->name(),
            $this->prod?->name(),
            $this->agent?->name(),
            $this->ingress?->name(),
            ...array_map(
                static fn (E2EInstance $instance): string => $instance->name(),
                array_values($this->additionalInstances),
            ),
        ])));
    }

    /**
     * @return array<string, E2EInstance|null>
     */
    private function allInstances(): array
    {
        return [
            'operator' => $this->control,
            'gateway' => $this->gateway,
            'dev' => $this->dev,
            'prod' => $this->prod,
            'agent' => $this->agent,
            'ingress' => $this->ingress,
            ...$this->additionalInstances,
        ];
    }

    /**
     * @param  array<string, E2EInstance>  $instances
     * @return array<string, E2EInstance>
     */
    private function additionalInstancesFrom(array $instances): array
    {
        return array_diff_key($instances, array_flip(['operator', 'control', 'gateway', 'dev', 'prod', 'agent', 'ingress']));
    }
}
