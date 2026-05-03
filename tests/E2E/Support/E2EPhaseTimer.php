<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class E2EPhaseTimer
{
    /** @var list<array{name: string, seconds: float}> */
    private array $events = [];

    public function measure(string $name, callable $callback): mixed
    {
        $start = microtime(true);

        try {
            return $callback();
        } finally {
            $this->events[] = [
                'name' => $name,
                'seconds' => microtime(true) - $start,
            ];
        }
    }

    /**
     * @return list<array{name: string, seconds: float}>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function flush(string $label): void
    {
        if (getenv('ORBIT_E2E_TIMINGS') !== '1') {
            return;
        }

        foreach ($this->events as $event) {
            fwrite(STDERR, sprintf(
                "[orbit-e2e] %s %s %.3fs\n",
                $label,
                $event['name'],
                $event['seconds'],
            ));
        }
    }
}
