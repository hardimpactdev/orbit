<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tools\SemverVersionNormalizer;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Tools\VpToolManager;

final class ResolvedVp
{
    public static function manager(string $home = '/opt/orbit/vite-plus', ?AgentCommandRunner $probe = null): VpToolManager
    {
        return new VpToolManager(
            new SemverVersionNormalizer,
            $probe ?? new FakeAgentCommandRunner()->answer('tool.inspect-vp-scope', ['binary' => $home.'/bin/vp']),
        );
    }
}
