<?php

declare(strict_types=1);

namespace App\Librarian;

interface CliSurface
{
    /**
     * @return list<CliCommand>
     */
    public function publicCommands(): array;
}
