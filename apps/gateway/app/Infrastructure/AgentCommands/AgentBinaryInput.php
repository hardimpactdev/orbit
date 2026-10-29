<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Infrastructure\Processes\ProtectedInput;
use RuntimeException;

/** An open private staging file, replayed from its start after a version mismatch. Its caller must not change it. */
final readonly class AgentBinaryInput
{
    private function __construct(private ProtectedInput $input, public int $bytes) {}

    public static function fromFile(string $path, int $maxBytes): self
    {
        $input = ProtectedInput::openFile($path);

        return self::checked($input, $maxBytes);
    }

    /** @param resource $stream A caller-owned private file; closing this value never closes the caller's file. */
    public static function fromStream(mixed $stream, int $maxBytes): self
    {
        return self::checked(ProtectedInput::borrowedStream($stream), $maxBytes);
    }

    private static function checked(ProtectedInput $input, int $maxBytes): self
    {
        $stat = fstat($input->stream());
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $maxBytes < 0 || $stat['size'] > $maxBytes) {
            throw new RuntimeException('Agent binary input is not a regular file within its limit.');
        }

        return new self($input, $stat['size']);
    }

    public function envelope(string $header, bool $credential = false): ProtectedInput
    {
        return ProtectedInput::prefixed($this->input, $header, $credential);
    }

    /** @return array{input: string} */
    public function __debugInfo(): array
    {
        return ['input' => '[PROTECTED]'];
    }
}
