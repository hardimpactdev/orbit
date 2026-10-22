<?php

declare(strict_types=1);

namespace Tests\Support {

    use Closure;

    /**
     * Advances only after real Predis protocol I/O, never in response to the host scheduler.
     * Pest eagerly loads these observers before PHP can cache global I/O function fallbacks.
     * With no active clock every observer forwards its syscall without changing behavior.
     */
    final class ValkeyDeadlineClock
    {
        public static ?self $active = null;

        public float $time = 0.0;

        public int $expiryChecks = 0;

        public int $readsAfterExpiry = 0;

        public bool $authenticated = false;

        public bool $configured = false;

        public string $fragment = '';

        /** @var list<int> */
        public array $waitBudgets = [];

        public bool $waitSettingsApplied = true;

        private string $written = '';

        public function __construct(private readonly bool $fragmented) {}

        public function now(): float
        {
            if ($this->time >= 0.2) {
                $this->expiryChecks++;
            }

            return $this->time;
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

        public function reading(): void
        {
            if ($this->time >= 0.2) {
                $this->readsAfterExpiry++;
            }
        }

        public function received(string|false $bytes, bool $bulk = false): void
        {
            if (! $this->authenticated && $bytes === ":1\r\n") {
                $this->authenticated = true;
                $this->time = 0.12;
            }
            if ($this->fragmented && $this->configured && $bulk && is_string($bytes)
                && ($this->fragment !== '' || str_starts_with($bytes, '/'))) {
                $this->fragment .= $bytes;
                if ($this->fragment === '/var/') {
                    $this->time = 0.2;
                }
            }
        }

        public function sent(string $bytes): void
        {
            $this->written .= $bytes;
            if (str_ends_with($this->written, "*3\r\n$6\r\nCONFIG\r\n$3\r\nGET\r\n$7\r\naclfile\r\n")) {
                $this->configured = true;
                if (! $this->fragmented) {
                    $this->time = 0.2;
                }
            }
        }
    }
}

namespace App\Infrastructure\DatabaseServers {

    use Tests\Support\ValkeyDeadlineClock;

    /** Keep the OS call real and record that authentication reduced each subsequent wait's budget. */
    function stream_set_timeout(mixed $stream, int $seconds, int $microseconds = 0): bool
    {
        $applied = \stream_set_timeout($stream, $seconds, $microseconds);
        if (ValkeyDeadlineClock::$active !== null) {
            ValkeyDeadlineClock::$active->waitBudgets[] = $seconds * 1_000_000 + $microseconds;
            ValkeyDeadlineClock::$active->waitSettingsApplied = ValkeyDeadlineClock::$active->waitSettingsApplied && $applied;
        }

        return $applied;
    }
}

namespace Predis\Connection\Resource {

    use Tests\Support\ValkeyDeadlineClock;

    /** Observe real line reads; do not replace transport results or raise fixture exceptions. */
    function fgets(mixed $stream): string|false
    {
        ValkeyDeadlineClock::$active?->reading();
        $bytes = \fgets($stream);
        ValkeyDeadlineClock::$active?->received($bytes);

        return $bytes;
    }

    /** Observe the real incomplete bulk read before expiring its next chunk's shared budget. */
    function fread(mixed $stream, int $length): string|false
    {
        ValkeyDeadlineClock::$active?->reading();
        $bytes = \fread($stream, $length);
        ValkeyDeadlineClock::$active?->received($bytes, bulk: true);

        return $bytes;
    }

    /** Sending CONFIG is the silent response's deadline milestone, after authentication. */
    function fwrite(mixed $stream, string $data, ?int $length = null): int|false
    {
        $written = $length === null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
        if (is_int($written) && $written > 0) {
            ValkeyDeadlineClock::$active?->sent(substr($data, 0, $written));
        }

        return $written;
    }
}
