<?php

declare(strict_types=1);

namespace App\E2E\Support;

use InvalidArgumentException;
use RuntimeException;

final readonly class DockerTopologyNetworkPlan
{
    private const int BaseSecondOctet = 90;

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
                return new self((self::runHash($runId) + $attempt) % 224);
            }

            $worker = (int) $token;

            if ($worker < 1 || $worker > 14) {
                throw new RuntimeException("Unsupported parallel test token [{$token}] for run-scoped Docker E2E subnet allocation.");
            }

            return new self((($worker - 1) * 16) + ((self::runHash($runId) + $attempt) % 16));
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
