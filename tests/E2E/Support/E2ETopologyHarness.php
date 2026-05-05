<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

final class E2ETopologyHarness
{
    /**
     * @param  array<string, string>  $checkouts
     */
    public function __construct(
        private E2ETopologyLease $lease,
        private array $checkouts = [],
        private readonly bool $cleanupOnRelease = true,
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
        $this->checkouts = E2ECurrentCheckout::installOnTopology($this->lease, $roles, $users);

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

    public function ssh(string $role, string $command, ?string $user = null, ?int $timeoutSeconds = null): ProcessResult
    {
        $user ??= $this->defaultUserFor($role);

        return E2ECommand::ssh(
            $this->instance($role),
            $user,
            $this->lease->sshKeyPair(),
            $command,
            $timeoutSeconds,
        );
    }

    public function instance(string $role): E2EInstance
    {
        return match ($role) {
            'control' => $this->lease->control(),
            'gateway' => $this->lease->gateway() ?? throw new RuntimeException('Topology does not include role [gateway].'),
            'dev' => $this->lease->devApp() ?? throw new RuntimeException('Topology does not include role [dev].'),
            'prod' => $this->lease->prodApp() ?? throw new RuntimeException('Topology does not include role [prod].'),
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
            'control' => E2EConfig::fromEnvironment()->controlUser,
            'gateway', 'dev', 'prod' => 'orbit',
            default => throw new RuntimeException("Unknown topology role [{$role}]."),
        };
    }
}
