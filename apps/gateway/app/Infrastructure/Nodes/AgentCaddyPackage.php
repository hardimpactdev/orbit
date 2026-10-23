<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\CaddyRelease;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/**
 * Installs the pinned Caddy release through the agent's `caddy.install-package` (`CaddyPackageProgram` holds the pin).
 * The result prints `CaddyPackageProgram::Changed` when the Node changed, as callers read it.
 */
final readonly class AgentCaddyPackage
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function install(Node $node): CommandResult
    {
        try {
            $result = $this->agents->run($node, 'caddy.install-package', [
                'release' => CaddyPackageProgram::RELEASE,
                'release_url' => CaddyPackageProgram::RELEASE_URL,
                'amd64_sha512' => CaddyPackageProgram::AMD64_SHA512,
                'arm64_sha512' => CaddyPackageProgram::ARM64_SHA512,
                'minimum_version' => CaddyRelease::MINIMUM,
                'kernel_setting_path' => CaddyPackageProgram::KERNEL_SETTING_PATH,
                'kernel_setting' => CaddyPackageProgram::KERNEL_SETTING,
                'legacy_paths' => ['/usr/share/keyrings/orbit-caddy.gpg', '/etc/apt/sources.list.d/orbit-caddy.sources'],
            ]);
        } catch (AgentCommandRefused $refusal) {
            $reason = $refusal->details['reason'] ?? $refusal->getMessage();

            return new CommandResult(1, '', is_string($reason) ? $reason : $refusal->getMessage(), 0, false);
        } catch (AgentRunnerFailure $failure) {
            return $failure->result ?? new CommandResult(255, '', $failure->getMessage(), 0, false);
        }

        $line = ($result['changed'] ?? false) === true ? CaddyPackageProgram::Changed : 'orbit-caddy-package-result=unchanged';

        return new CommandResult(0, $line."\n", '', 0, false);
    }
}
