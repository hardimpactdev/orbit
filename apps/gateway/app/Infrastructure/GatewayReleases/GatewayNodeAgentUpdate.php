<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;

/**
 * Brings the Gateway Node's own `orbit-agent` to the release's pin before the Gateway accepts command work. The
 * fleet rollout leaves the Gateway's machine out, so the release's runtime handoff updates it here, through the
 * agent's own installer as a local process, never over SSH to itself (ADR 0225).
 *
 * It never throws: the result is part of the release record, and a failure there never fails the release.
 */
final readonly class GatewayNodeAgentUpdate
{
    /** The installer's own lock wait and health window, plus transfer and start-up time. */
    public const int TimeoutSeconds = 300;

    public function __construct(
        private AgentCommandRunner $agents,
        private AgentDelivery $delivery,
    ) {}

    /**
     * @return array<string, mixed> `outcome` is `unchanged`, `updated`, `skipped`, or `failed`
     */
    public function converge(Node $gateway): array
    {
        if ($gateway->platform !== 'linux') {
            return ['outcome' => 'skipped', 'reason' => 'platform'];
        }

        try {
            $replaced = $this->agents->install($gateway);
        } catch (AgentCommandRefused $refused) {
            $cause = $refused->details['cause'] ?? null;

            return $this->failed($gateway, is_string($cause) ? $cause : $refused->errorCode, $refused->getMessage());
        } catch (AgentRunnerFailure $failure) {
            return $this->failed($gateway, 'agent.install_failed', $failure->getMessage());
        }

        return ['outcome' => $replaced ? 'updated' : 'unchanged', 'version' => $this->delivery->reportedVersion($gateway)];
    }

    /** @return array{outcome: string, version: string, error_code: string, message: string} */
    private function failed(Node $gateway, string $errorCode, string $message): array
    {
        return ['outcome' => 'failed', 'version' => $this->delivery->reportedVersion($gateway), 'error_code' => $errorCode, 'message' => mb_substr($message, 0, 200)];
    }
}
