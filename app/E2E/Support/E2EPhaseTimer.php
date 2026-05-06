<?php

declare(strict_types=1);

namespace App\E2E\Support;

final class E2EPhaseTimer
{
    /** @var list<array{name: string, seconds: float}> */
    private array $events = [];

    public function __construct(
        private readonly bool $stream = false,
        /**
         * @var (\Closure(string): void)|null
         */
        private readonly ?\Closure $writer = null
    ) {}

    public function measure(string $name, callable $callback): mixed
    {
        $this->write("{$name} started");

        $start = microtime(true);

        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            $seconds = microtime(true) - $start;
            $this->record($name, $seconds);
            $this->write(sprintf(
                '%s failed %.3fs %s: %s',
                $name,
                $seconds,
                get_debug_type($exception),
                $exception->getMessage(),
            ));

            throw $exception;
        }

        $seconds = microtime(true) - $start;
        $this->record($name, $seconds);
        $this->write(sprintf('%s done %.3fs', $name, $seconds));

        return $result;
    }

    /**
     * @return list<array{name: string, seconds: float}>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function streamsCheckpoints(): bool
    {
        return $this->stream;
    }

    public function flush(string $label): void
    {
        if ($this->stream || getenv('ORBIT_E2E_TIMINGS') !== '1') {
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

    private function record(string $name, float $seconds): void
    {
        $this->events[] = [
            'name' => $name,
            'seconds' => $seconds,
        ];
    }

    private function write(string $message): void
    {
        if (! $this->stream) {
            return;
        }

        $line = "[orbit-e2e] {$message}";

        if ($this->writer !== null) {
            ($this->writer)($line);

            return;
        }

        fwrite(STDERR, $line.PHP_EOL);
    }
}
