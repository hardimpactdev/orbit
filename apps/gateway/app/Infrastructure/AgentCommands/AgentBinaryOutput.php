<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use InvalidArgumentException;

/** A private sink owned and closed by the caller. Binary contents never become a CommandResult. */
final class AgentBinaryOutput
{
    private int $bytes = 0;

    /** @param resource $file */
    public function __construct(private readonly mixed $file, public readonly int $maxBytes)
    {
        if (! is_resource($file) || $maxBytes < 1) {
            throw new InvalidArgumentException('Binary output needs an open sink and a positive bound.');
        }
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function write(#[\SensitiveParameter] string $bytes): bool
    {
        $length = strlen($bytes);
        if (! is_resource($this->file) || $length > $this->maxBytes - $this->bytes) {
            return false;
        }
        for ($offset = 0; $offset < $length;) {
            $written = @fwrite($this->file, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
            $this->bytes += $written;
        }

        return true;
    }

    /** @return array{bytes: int, max_bytes: int} */
    public function __debugInfo(): array
    {
        return ['bytes' => $this->bytes, 'max_bytes' => $this->maxBytes];
    }
}
