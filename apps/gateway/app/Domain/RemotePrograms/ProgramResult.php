<?php

declare(strict_types=1);

namespace App\Domain\RemotePrograms;

/** The result of one program run: a declared outcome, its data, and the program's log lines. */
final readonly class ProgramResult
{
    /** @param  array<array-key, mixed>  $data */
    public function __construct(
        public string $outcome,
        public array $data,
        public string $log,
    ) {}

    /** The string at `$key` in the data, or null when it is missing or not a string. */
    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
