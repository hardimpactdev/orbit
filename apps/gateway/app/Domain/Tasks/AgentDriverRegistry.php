<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Node;

final readonly class AgentDriverRegistry
{
    /** @var array<string, AgentDriver> */
    private array $drivers;

    /** @param iterable<AgentDriver> $drivers */
    public function __construct(iterable $drivers)
    {
        $registered = [];
        foreach ($drivers as $driver) {
            if (isset($registered[$driver->key()])) {
                throw new AgentDriverException('Duplicate agent driver registration.');
            }
            $registered[$driver->key()] = $driver;
        }
        $this->drivers = $registered;
    }

    public function get(string $key): AgentDriver
    {
        return $this->drivers[$key] ?? throw new AgentDriverException('The recorded agent driver is unavailable.');
    }

    public function anyAllows(Node $node): bool
    {
        return array_any($this->drivers, static fn (AgentDriver $driver): bool => $driver->allows($node));
    }
}
