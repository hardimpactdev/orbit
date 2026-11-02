<?php

declare(strict_types=1);

namespace App\Support\Console;

interface StandardInputReader
{
    public function read(): string;

    /** Whether standard input is an interactive terminal rather than a pipe or file. */
    public function isTerminal(): bool;
}
