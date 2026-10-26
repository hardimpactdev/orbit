<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Node;
use Illuminate\Container\Attributes\Tag;

/**
 * Converges the Gateway PHP-FPM pool and the contributed start hooks ({@see GatewayServiceStartHook}). The container
 * binds it once, with the contributed hooks, for Gateway web convergence and the release handoff.
 */
final readonly class NativeGatewayFpmConverger
{
    /** @param iterable<GatewayServiceStartHook> $startHooks */
    public function __construct(
        private AgentCommandRunner $agents,
        #[Tag(GatewayServiceStartHook::class)] private iterable $startHooks,
    ) {}

    /** Returns whether PHP-FPM activated a pool. The agent owns comparison, validation, and recovery. */
    public function converge(Node $node, string $pool): bool
    {
        $hooks = [];
        foreach ($this->startHooks as $hook) {
            $hooks[] = ['command' => $hook->command(), 'input' => $hook->input(config()->string('orbit.gateway_checkout'), config()->string('orbit.home'))];
        }
        try {
            $result = $this->agents->run($node, 'gateway.converge-fpm', ['pool' => $pool, 'hooks' => $hooks]);
            if (! is_bool($result['activated'] ?? null)) {
                throw new AgentRunnerFailure('gateway.converge-fpm', AgentRunnerFailure::MalformedResult, 'The agent returned no PHP-FPM activation result.');
            }

            return $result['activated'];
        } catch (AgentCommandRefused $refused) {
            [$step, $code] = match (true) {
                $refused->errorCode === 'gateway.fpm_invalid', ($refused->details['stage'] ?? null) === 'validate' => ['gateway-fpm-validate', 'gateway.fpm_config_invalid'],
                $refused->errorCode === 'gateway.fpm_hook_failed' => ['gateway-fpm-start-hook', 'gateway.fpm_start_failed'],
                $refused->errorCode === 'gateway.fpm_recovery_failed' => ['gateway-fpm-recover', 'gateway.fpm_recovery_failed'],
                in_array($refused->details['stage'] ?? null, ['enable', 'reload-or-restart'], true) => ['gateway-fpm-reload', 'gateway.fpm_start_failed'],
                default => ['gateway-fpm-install', 'gateway.fpm_config_install_failed'],
            };
            throw new NodeProvisioningException($step, $code, $refused->getMessage(), previous: $refused, result: AgentStep::output($refused));
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException('gateway-fpm-install', 'gateway.fpm_config_install_failed', 'Gateway PHP-FPM did not return a result.', previous: $failure, result: $failure->result);
        }
    }

    public function connections(Node $node): int
    {
        $result = $this->agents->run($node, 'gateway.inspect-fpm-connections');
        if (! is_int($result['connections'] ?? null) || $result['connections'] < 0) {
            throw new AgentRunnerFailure('gateway.inspect-fpm-connections', AgentRunnerFailure::MalformedResult, 'The agent returned no PHP-FPM connection count.');
        }

        return $result['connections'];
    }
}
