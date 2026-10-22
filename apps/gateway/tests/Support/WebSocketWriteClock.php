<?php

declare(strict_types=1);

namespace Tests\Support {

    use Closure;
    use LogicException;

    /** Controls only the application deadline; the TLS connection and writes stay real. */
    final class WebSocketWriteClock
    {
        public static ?self $active = null;

        /** @var list<float> */
        public array $reads = [];

        /** @var list<int> */
        public array $pauses = [];

        public function now(): float
        {
            // Start the budget, observe a zero-byte write at its boundary, then expire it.
            $time = match (count($this->reads)) {
                0 => 0.0,
                1 => 5.0,
                2 => 5.001,
                default => throw new LogicException('The pong write continued after its deadline.'),
            };
            $this->reads[] = $time;

            return $time;
        }

        public function run(Closure $operation): void
        {
            $previous = self::$active;
            self::$active = $this;

            try {
                $operation();
            } finally {
                self::$active = $previous;
            }
        }
    }
}

namespace App\Infrastructure\AgentView {

    use Tests\Support\WebSocketWriteClock;

    function microtime(bool $as_float = false): string|float
    {
        if ($as_float && WebSocketWriteClock::$active !== null) {
            return WebSocketWriteClock::$active->now();
        }

        return \microtime($as_float);
    }

    function usleep(int $microseconds): void
    {
        if (WebSocketWriteClock::$active !== null) {
            WebSocketWriteClock::$active->pauses[] = $microseconds;

            return;
        }

        \usleep($microseconds);
    }
}
