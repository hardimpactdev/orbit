<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseServers;

use App\Infrastructure\Processes\CommandDeadline;
use Predis\Connection\Resource\Stream;

/** Applies the remaining shared budget before every handshake, reply line, and bulk chunk. */
final class DeadlineValkeyStream extends Stream
{
    /** @param resource $socket */
    public function __construct(
        private readonly mixed $socket,
        private readonly CommandDeadline $deadline,
        private readonly float $timeout,
    ) {
        parent::__construct($socket);
    }

    public function read(int $length): string
    {
        $this->limitWait();

        return parent::read($length);
    }

    public function write(string $string): int
    {
        $this->limitWait();

        return parent::write($string);
    }

    private function limitWait(): void
    {
        $timeout = $this->deadline->cap($this->timeout);
        $seconds = (int) floor($timeout);
        stream_set_timeout($this->socket, $seconds, (int) (($timeout - $seconds) * 1_000_000));
    }
}
