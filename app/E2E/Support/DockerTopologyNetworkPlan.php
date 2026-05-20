<?php

declare(strict_types=1);

namespace App\E2E\Support;

use InvalidArgumentException;
use RuntimeException;

final readonly class DockerTopologyNetworkPlan
{
    public function __construct(
        private int $secondOctet,
    ) {
        if ($this->secondOctet < 0 || $this->secondOctet > 255) {
            throw new InvalidArgumentException('Docker topology network octet must be between 0 and 255.');
        }
    }

    public static function fromEnvironment(): self
    {
        $token = getenv('TEST_TOKEN');

        if (! is_string($token) || $token === '') {
            return new self(6);
        }

        $worker = (int) $token;

        if ($worker < 1 || $worker > 190) {
            throw new RuntimeException("Unsupported parallel test token [{$token}] for Docker E2E subnet allocation.");
        }

        return new self(60 + $worker);
    }

    public function subnet(): string
    {
        return "10.{$this->secondOctet}.0.0/16";
    }

    public function ipForRole(string $role): string
    {
        return match ($role) {
            'gateway' => $this->ip(2),
            'control' => $this->ip(3),
            'dev' => $this->ip(4),
            'prod' => $this->ip(5),
            'agent' => $this->ip(6),
            default => throw new RuntimeException("Unknown Docker topology role {$role}."),
        };
    }

    private function ip(int $host): string
    {
        return "10.{$this->secondOctet}.0.{$host}";
    }
}
