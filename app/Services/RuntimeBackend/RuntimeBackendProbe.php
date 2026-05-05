<?php

declare(strict_types=1);

namespace App\Services\RuntimeBackend;

use App\Contracts\RemoteShell;
use App\Data\RuntimeBackend\RuntimeBackendProbeResult;
use App\Models\Node;

final readonly class RuntimeBackendProbe
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function check(Node $node): RuntimeBackendProbeResult
    {
        $result = $this->remoteShell->run($node, $this->script(), ['timeout' => 15]);

        return new RuntimeBackendProbeResult(
            available: $result->successful(),
            exitCode: $result->exitCode,
            output: trim($result->output()),
        );
    }

    private function script(): string
    {
        return 'command -v supervisorctl >/dev/null 2>&1 && supervisorctl status >/dev/null 2>&1';
    }
}
