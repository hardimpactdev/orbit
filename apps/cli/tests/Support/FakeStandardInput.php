<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Console\StandardInputReader;

/** Standard input for a test: piped contents, or an interactive terminal that must not be read. */
final class FakeStandardInput implements StandardInputReader
{
    public int $reads = 0;

    public function __construct(private readonly ?string $piped) {}

    public static function piped(string $contents): self
    {
        return new self($contents);
    }

    public static function terminal(): self
    {
        return new self(null);
    }

    public function read(): string
    {
        $this->reads++;

        return $this->piped ?? throw new \LogicException('A terminal must be prompted, not read.');
    }

    public function isTerminal(): bool
    {
        return $this->piped === null;
    }
}
