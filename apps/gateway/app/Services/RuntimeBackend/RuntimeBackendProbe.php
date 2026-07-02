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
        $result = $this->remoteShell->run($node, $this->script($node), ['timeout' => 15]);

        return new RuntimeBackendProbeResult(
            available: $result->successful(),
            exitCode: $result->exitCode,
            output: trim($result->output()),
        );
    }

    public function remoteShell(): RemoteShell
    {
        return $this->remoteShell;
    }

    private function script(Node $node): string
    {
        if ($this->usesDockerProvider($node)) {
            return 'command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1';
        }

        return 'command -v systemctl >/dev/null 2>&1 && systemctl --version >/dev/null 2>&1';
    }

    private function usesDockerProvider(Node $node): bool
    {
        $platform = strtolower((string) $node->platform);

        return str_starts_with($platform, 'macos') || $platform === 'darwin';
    }
}
