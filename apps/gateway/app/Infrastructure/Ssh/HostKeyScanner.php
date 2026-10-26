<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

use App\Models\Node;

interface HostKeyScanner
{
    /** Scans from the Gateway, or on the `$via` jump host for a host that only it can reach. */
    public function scan(string $host, int $port, ?Node $via = null): HostKey;
}
