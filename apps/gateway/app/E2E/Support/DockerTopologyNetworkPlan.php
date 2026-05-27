<?php

declare(strict_types=1);

namespace App\E2E\Support;

use InvalidArgumentException;
use RuntimeException;

final readonly class DockerTopologyNetworkPlan
{
    private const int BaseSecondOctet = 90;

    private const int RunScopedSubnetsPerWorker = 14;

    private const int MaxRunScopedParallelWorkers = 16;

    private const int RunScopedSubnetCount = self::RunScopedSubnetsPerWorker * self::MaxRunScopedParallelWorkers;

    public function __construct(
        private int $thirdOctet,
    ) {
        if ($this->thirdOctet < 0 || $this->thirdOctet > 255) {
            throw new InvalidArgumentException('Docker topology network octet must be between 0 and 255.');
        }
    }

    public static function fromEnvironment(?string $runId = null, int $attempt = 0): self
    {
        if ($attempt < 0) {
            throw new InvalidArgumentException('Docker topology network allocation attempt must be zero or greater.');
        }

        $token = getenv('TEST_TOKEN');

        if ($runId !== null && $runId !== '') {
            if (! is_string($token) || $token === '') {
                return new self((self::runHash($runId) + $attempt) % self::RunScopedSubnetCount);
            }

            $worker = (int) $token;

            if ($worker < 1 || $worker > self::MaxRunScopedParallelWorkers) {
                throw new RuntimeException("Unsupported parallel test token [{$token}] for run-scoped Docker E2E subnet allocation.");
            }

            return new self((($worker - 1) * self::RunScopedSubnetsPerWorker) + ((self::runHash($runId) + $attempt) % self::RunScopedSubnetsPerWorker));
        }

        if (! is_string($token) || $token === '') {
            return new self(224);
        }

        $worker = (int) $token;

        if ($worker < 1 || $worker > 31) {
            throw new RuntimeException("Unsupported parallel test token [{$token}] for Docker E2E subnet allocation.");
        }

        return new self(224 + $worker);
    }

    public static function runScopedAttemptsPerWorker(): int
    {
        return self::RunScopedSubnetsPerWorker;
    }

    public static function maxRunScopedParallelWorkers(): int
    {
        return self::MaxRunScopedParallelWorkers;
    }

    public function subnet(): string
    {
        return '10.'.self::BaseSecondOctet.".{$this->thirdOctet}.0/24";
    }

    public function ipForRole(string $role): string
    {
        return match ($role) {
            'gateway' => $this->ip(2),
            'operator', 'control' => $this->ip(3),
            'dev' => $this->ip(4),
            'prod' => $this->ip(5),
            'agent' => $this->ip(6),
            'ingress' => $this->ip(7),
            default => throw new RuntimeException("Unknown Docker topology role {$role}."),
        };
    }

    private function ip(int $host): string
    {
        return '10.'.self::BaseSecondOctet.".{$this->thirdOctet}.{$host}";
    }

    private static function runHash(string $runId): int
    {
        return (int) sprintf('%u', crc32($runId));
    }
}
