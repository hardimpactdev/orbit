<?php

declare(strict_types=1);

namespace App\Services\Executor;

/**
 * Captures non-TTY stdin once so operation-token verification can bind the same
 * payload the command later consumes. Piped force_remote_host work delivers
 * bound input on the host CLI process stdin; verification must not leave the
 * command with an empty stream after hashing that payload.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class OperationStdinBuffer
{
    private ?string $contents = null;

    private bool $captured = false;

    public function captureFromProcessStdin(): void
    {
        if ($this->captured) {
            return;
        }

        $this->captured = true;
        $this->contents = $this->readOptionalStdin();
    }

    /**
     * Capture once from a Symfony/console stream (tests and wired CLI input).
     * Interactive TTY streams are treated as no operation payload.
     *
     * @param  resource  $stream
     */
    public function captureFromStream(mixed $stream): void
    {
        if ($this->captured) {
            return;
        }

        $this->captured = true;

        if (! is_resource($stream) || $this->streamIsInteractiveTty($stream)) {
            $this->contents = null;

            return;
        }

        $contents = stream_get_contents($stream);
        $this->contents = is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * Prime a known stdin payload for tests or host-boundary harnesses that
     * cannot re-open process STDIN after capture.
     */
    public function prime(string $contents): void
    {
        $this->captured = true;
        $this->contents = $contents === '' ? null : $contents;
    }

    public function hasCaptured(): bool
    {
        return $this->captured;
    }

    /**
     * Clear capture state so the next command invocation can bind a fresh payload.
     * Production CLI runs one internal command per process; tests may run many.
     */
    public function reset(): void
    {
        $this->captured = false;
        $this->contents = null;
    }

    public function contents(): ?string
    {
        if (! $this->captured) {
            $this->captureFromProcessStdin();
        }

        return $this->contents;
    }

    /**
     * Return buffered stdin for command handlers. Empty string when no payload
     * was captured (matches stream_get_contents on an empty stream).
     */
    public function take(): string
    {
        if (! $this->captured) {
            $this->captureFromProcessStdin();
        }

        return $this->contents ?? '';
    }

    private function readOptionalStdin(): ?string
    {
        if (! defined('STDIN') || ! is_resource(STDIN)) {
            return null;
        }

        // Interactive TTY means no piped operation-token input payload.
        if ($this->streamIsInteractiveTty(STDIN)) {
            return null;
        }

        $contents = stream_get_contents(STDIN);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return $contents;
    }

    /**
     * @param  resource  $stream
     */
    private function streamIsInteractiveTty(mixed $stream): bool
    {
        if (! is_resource($stream) || ! function_exists('stream_isatty')) {
            return false;
        }

        $previous = set_error_handler(static fn (): bool => true);

        try {
            return stream_isatty($stream) === true;
        } finally {
            restore_error_handler();

            if ($previous !== null) {
                set_error_handler($previous);
            }
        }
    }
}
