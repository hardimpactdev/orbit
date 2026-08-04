<?php

declare(strict_types=1);

namespace App\Services\Processes;

/**
 * Injectable client-disconnect probe for process SSE follow loops.
 */
interface ProcessStreamConnection
{
    public function aborted(): bool;
}
