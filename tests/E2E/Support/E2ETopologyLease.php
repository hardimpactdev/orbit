<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class E2ETopologyLease
{
    private bool $cleaned = false;

    /**
     * @param  \Closure(E2EPhaseTimer): array{
     *     instances: array<string, E2EInstance>,
     *     snapshotReset: \Closure(E2EPhaseTimer): void|null
     * }  $rebuild
     * @param  \Closure(E2EPhaseTimer): void|null  $snapshotReset
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
    ) {}

    public function kind(): E2ETopologyKind
    {
        return $this->kind;
    }

    public function control(): E2EInstance
    {
        return $this->control;
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

    public function sshKeyPair(): SshKeyPair
    {
        return $this->sshKeyPair;
    }

    public function cleanup(?E2EPhaseTimer $timer = null): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;

        $timer ??= new E2EPhaseTimer;

        foreach (['control' => $this->control, 'gateway' => $this->gateway, 'dev' => $this->dev, 'prod' => $this->prod] as $role => $instance) {
            if ($instance !== null) {
                $timer->measure("cleanup.{$role}", fn () => $instance->delete());
            }
        }
    }

    public function reset(): void
    {
        $strategy = getenv('ORBIT_E2E_TOPOLOGY_RESET');
        $strategy = is_string($strategy) && $strategy !== '' ? $strategy : 'fresh-clone';

        $timer = new E2EPhaseTimer;

        try {
            if ($strategy === 'snapshot-restore' && $this->snapshotReset !== null) {
                ($this->snapshotReset)($timer);
                $this->cleaned = false;

                return;
            }

            $this->cleanup($timer);
            $instances = ($this->rebuild)($timer);

            $this->control = $instances['control'];
            $this->gateway = $instances['gateway'] ?? null;
            $this->dev = $instances['dev'] ?? null;
            $this->prod = $instances['prod'] ?? null;
            $this->cleaned = false;
        } finally {
            $timer->flush('reset');
        }
    }
}
