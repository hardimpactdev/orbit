<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

final class E2ETopologyHarness
{
    /**
     * @param  array<string, string>  $checkouts
     */
    public function __construct(
        private readonly E2ETopologyLease $lease,
        private array $checkouts = [],
        private readonly bool $cleanupOnRelease = true,
        private ?E2EPhaseTimer $timer = null,
    ) {}

    public function lease(): E2ETopologyLease
    {
        return $this->lease;
    }

    public function kind(): E2ETopologyKind
    {
        return $this->lease->kind();
    }

    /**
     * @param  list<string>|null  $roles
     * @param  array<string, string>  $users
     */
    public function withCurrentCheckout(?array $roles = null, array $users = []): self
    {
        $timer = $this->checkoutTimer()?->child('checkout');

        try {
            $this->checkouts = E2ECurrentCheckout::installOnTopology($this->lease, $roles, $users, $timer);
        } finally {
            $timer?->flush('checkout');
        }

        return $this;
    }

    public function setTimer(E2EPhaseTimer $timer): self
    {
        $this->timer = $timer;

        return $this;
    }

    /**
     * @param  array<string, string>  $checkouts
     */
    public function setCheckouts(array $checkouts): void
    {
        $this->checkouts = $checkouts;
    }

    public function checkout(string $role): string
    {
        if ($role === 'operator') {
            return $this->checkouts['operator']
                ?? $this->checkouts['control']
                ?? throw new RuntimeException("Current checkout has not been installed for role [{$role}].");
        }

        if ($role === 'control') {
            return $this->checkouts['control']
                ?? $this->checkouts['operator']
                ?? throw new RuntimeException("Current checkout has not been installed for role [{$role}].");
        }

        return $this->checkouts[$role]
            ?? throw new RuntimeException("Current checkout has not been installed for role [{$role}].");
    }

    /**
     * @return array<string, string>
     */
    public function checkouts(): array
    {
        return $this->checkouts;
    }

    public function ssh(string $role, string $command, ?string $user = null, ?int $timeoutSeconds = null, bool $allowFailure = false): ProcessResult
    {
        $user ??= $this->defaultUserFor($role);

        return E2ECommand::ssh(
            $this->instance($role),
            $user,
            $this->lease->sshKeyPair(),
            $command,
            $timeoutSeconds,
            $allowFailure,
        );
    }

    public function instance(string $role): E2EInstance
    {
        return match ($role) {
            'operator' => $this->lease->operator(),
            'control' => $this->lease->control(),
            'gateway' => $this->lease->gateway() ?? throw new RuntimeException('Topology does not include role [gateway].'),
            'dev' => $this->lease->devApp() ?? throw new RuntimeException('Topology does not include role [dev].'),
            'prod' => $this->lease->prodApp() ?? throw new RuntimeException('Topology does not include role [prod].'),
            'agent' => $this->lease->agent() ?? throw new RuntimeException('Topology does not include role [agent].'),
            'ingress' => $this->lease->ingress() ?? throw new RuntimeException('Topology does not include role [ingress].'),
            default => throw new RuntimeException("Unknown topology role [{$role}]."),
        };
    }

    public function reset(): void
    {
        E2ECurrentCheckout::flushCache();

        $this->lease->reset();
        $this->checkouts = [];
    }

    public function cleanup(): void
    {
        if (! $this->cleanupOnRelease) {
            return;
        }

        $this->lease->cleanup();
    }

    /**
     * @return list<string>
     */
    public function instanceNames(): array
    {
        return $this->lease->instanceNames();
    }

    private function defaultUserFor(string $role): string
    {
        return match ($role) {
            'operator' => E2EConfig::fromEnvironment()->operatorUser,
            'control' => E2EConfig::fromEnvironment()->operatorUser,
            'gateway', 'dev', 'prod', 'agent', 'ingress' => 'orbit',
            default => throw new RuntimeException("Unknown topology role [{$role}]."),
        };
    }

    private function checkoutTimer(): ?E2EPhaseTimer
    {
        if ($this->timer !== null) {
            return $this->timer;
        }

        if (getenv('ORBIT_E2E_TIMINGS') !== '1') {
            return null;
        }

        $this->timer = new E2EPhaseTimer;

        return $this->timer;
    }
}
