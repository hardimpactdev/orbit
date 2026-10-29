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
 * Installs the pinned Caddy release through the agent's `caddy.install-package` (this adapter holds the release pin).
 * The result prints `self::Changed` when the Node changed, as callers read it.
 */
final readonly class AgentCaddyPackage
{
    public const string RELEASE = '2.11.7';

    /** From `caddy_2.11.7_checksums.txt`, whose sigstore signature names Caddy's release workflow. */
    public const string AMD64_SHA512 = '47e8351c2317b427af14a103e763ca1118a3d2396a88b4c0669cdec9c4a68a957690194e2423a1633f53135741c33a41bdac2b55515b7d0f7adc8b733add50d9';

    public const string Changed = 'orbit-caddy-package-result=changed';

    public function __construct(private AgentCommandRunner $agents) {}

    /** @return array<string, mixed> */
    public static function input(): array
    {
        return [
            'release' => self::RELEASE,
            'amd64_sha512' => self::AMD64_SHA512,
            'minimum_version' => CaddyRelease::MINIMUM,
        ];
    }

    public function install(Node $node): CommandResult
    {
        try {
            $result = $this->agents->run($node, 'caddy.install-package', self::input());
        } catch (AgentCommandRefused $refusal) {
            $reason = $refusal->details['reason'] ?? $refusal->getMessage();

            return new CommandResult(1, '', is_string($reason) ? $reason : $refusal->getMessage(), 0, false);
        } catch (AgentRunnerFailure $failure) {
            return $failure->result ?? new CommandResult(255, '', $failure->getMessage(), 0, false);
        }

        $line = ($result['changed'] ?? false) === true ? self::Changed : 'orbit-caddy-package-result=unchanged';

        return new CommandResult(0, $line."\n", '', 0, false);
    }
}
