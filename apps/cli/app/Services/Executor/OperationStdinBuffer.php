<?php

declare(strict_types=1);

namespace App\Services\Executor;

/**
 * Captures non-TTY stdin once so operation-token verification can bind the same
 * payload the command later consumes. Piped force_remote_host work delivers
 * bound input on the host CLI process stdin; verification must not leave the
 * command with an empty stream after hashing that payload.
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
     * Prime a known stdin payload for tests or host-boundary harnesses that
     * cannot re-open process STDIN after capture.
     */
    public function prime(string $contents): void
    {
        $this->captured = true;
        $this->contents = $contents === '' ? null : $contents;
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
        if ($this->stdinIsInteractiveTty()) {
            return null;
        }

        $contents = stream_get_contents(STDIN);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return $contents;
    }

    private function stdinIsInteractiveTty(): bool
    {
        if (! function_exists('stream_isatty')) {
            return false;
        }

        $previous = set_error_handler(static fn (): bool => true);

        try {
            return stream_isatty(STDIN) === true;
        } finally {
            restore_error_handler();

            if ($previous !== null) {
                set_error_handler($previous);
            }
        }
    }
}
