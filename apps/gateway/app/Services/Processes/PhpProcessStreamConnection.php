<?php

declare(strict_types=1);

namespace App\Services\Processes;

final class PhpProcessStreamConnection implements ProcessStreamConnection
{
    public function aborted(): bool
    {
        return connection_aborted() === 1;
    }
}
