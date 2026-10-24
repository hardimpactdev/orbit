<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use Illuminate\Container\Attributes\Tag;

/**
 * Converges the Gateway PHP-FPM pool and the contributed start hooks ({@see GatewayServiceStartHook}). The container
 * binds it once, with the contributed hooks, for Gateway web convergence and the release handoff.
 */
final readonly class NativeGatewayFpmConverger
{
    private const string LIVE_POOL = '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf';

    /** @param iterable<GatewayServiceStartHook> $startHooks */
    public function __construct(
        private ProcessRunner $processes,
        private AgentCommandRunner $agents,
        #[Tag(GatewayServiceStartHook::class)] private iterable $startHooks,
    ) {}

    /**
     * Installs the pool and the start hooks, runs each hook, and activates the pool. A hook that breaks the contract
     * stops convergence before anything is installed.
     */
    public function converge(Node $node, string $generatedPool): void
    {
        $version = bin2hex(random_bytes(8));
        $candidateDirectory = "/etc/php/8.5/fpm/orbit-candidates/{$version}";
        $candidateMain = $candidateDirectory.'/php-fpm.conf';
        $candidatePool = $candidateDirectory.'/pool.d/orbit-gateway.conf';

        try {
            $this->stage($generatedPool, $candidateDirectory, $candidateMain, $candidatePool);
            $this->run(
                step: 'gateway-fpm-validate',
                errorCode: 'gateway.fpm_config_invalid',
                arguments: ['sudo', 'php-fpm8.5', '--test', '--fpm-config', $candidateMain],
            );
            $this->installStartHooks($node, true);
            $this->run(
                step: 'gateway-fpm-install',
                errorCode: 'gateway.fpm_config_install_failed',
                arguments: ['sudo', 'mv', '-f', '--', $candidatePool, self::LIVE_POOL],
            );
            $this->cleanup($candidateDirectory);
        } catch (NodeProvisioningException $exception) {
            $this->cleanup($candidateDirectory);

            throw $exception;
        }

        $this->run(
            step: 'gateway-fpm-enable',
            errorCode: 'gateway.fpm_start_failed',
            arguments: ['sudo', 'systemctl', 'enable', 'php8.5-fpm'],
        );
        $this->run(
            step: 'gateway-fpm-reload',
            errorCode: 'gateway.fpm_start_failed',
            arguments: ['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm'],
        );
    }

    private function stage(
        string $generatedPool,
        string $candidateDirectory,
        string $candidateMain,
        string $candidatePool,
    ): void {
        $this->run(
            step: 'gateway-fpm-stage',
            errorCode: 'gateway.fpm_config_install_failed',
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $generatedPool,
                $candidateDirectory,
                $candidateMain,
                $candidatePool,
            ],
            input: <<<'BASH'
                replacement=$1
                candidate_directory=$2
                candidate_main=$3
                candidate_pool=$4
                candidates_directory=$(dirname "$candidate_directory")
                candidate_pool_directory=$(dirname "$candidate_pool")
                live_main=/etc/php/8.5/fpm/php-fpm.conf
                install -d -o root -g root -m 0755 "$candidates_directory" "$candidate_directory" "$candidate_pool_directory"

                for pool in /etc/php/8.5/fpm/pool.d/*.conf; do
                    if [ ! -e "$pool" ]; then
                        continue
                    fi

                    pool_name=$(basename "$pool")
                    if [ "$pool_name" = orbit-gateway.conf ]; then
                        continue
                    fi

                    cp --preserve=mode,ownership -- "$pool" "$candidate_pool_directory/$pool_name"
                done

                install -o root -g root -m 0644 -- "$replacement" "$candidate_pool"
                awk -v candidate_pool_pattern="$candidate_pool_directory/*.conf" '
                    BEGIN { replacement_count = 0 }
                    /^[[:space:]]*include[[:space:]]*=[[:space:]]*\/etc\/php\/8\.5\/fpm\/pool\.d\/\*\.conf[[:space:]]*$/ {
                        print "include = " candidate_pool_pattern
                        replacement_count++
                        next
                    }
                    { print }
                    END { if (replacement_count != 1) exit 1 }
                ' "$live_main" > "$candidate_main"
                chown root:root "$candidate_main"
                chmod 0644 "$candidate_main"
                BASH,
        );
    }

    /** Installs compiled hook configuration; execution is requested only before activating a changed pool. */
    public function installStartHooks(Node $node, bool $run = false): void
    {
        $hooks = [];
        foreach ($this->startHooks as $hook) {
            $hooks[] = ['command' => $hook->command(), 'input' => $hook->input(config()->string('orbit.gateway_checkout'), config()->string('orbit.home'))];
        }
        try {
            $this->agents->run($node, 'gateway.converge-start-hooks', ['hooks' => $hooks, 'run' => $run]);
        } catch (AgentCommandRefused $refused) {
            [$step, $code] = match ($refused->errorCode) {
                'gateway.start_hooks_invalid' => ['gateway-fpm-start-hook-validate', 'gateway.fpm_config_invalid'],
                'gateway.start_hook_failed' => ['gateway-fpm-start-hook', 'gateway.fpm_start_failed'],
                default => ['gateway-fpm-start-hook-install', 'gateway.fpm_config_install_failed'],
            };
            throw new NodeProvisioningException($step, $code, $refused->getMessage(), previous: $refused, result: AgentStep::output($refused));
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException('gateway-fpm-start-hook-install', 'gateway.fpm_config_install_failed', 'Gateway startup hooks did not return a result.', previous: $failure, result: $failure->result);
        }
    }

    private function cleanup(string $candidateDirectory): void
    {
        $this->processes->run(new ProcessInvocation(['sudo', 'rm', '-rf', '--', $candidateDirectory]));
    }

    /** @param non-empty-list<string> $arguments */
    private function run(string $step, string $errorCode, array $arguments, ?string $input = null, ?string $message = null): void
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: $arguments,
            timeout: 60.0,
            input: $input,
        ));

        if (! $result->succeeded()) {
            throw new NodeProvisioningException(
                step: $step,
                errorCode: $errorCode,
                message: $message ?? "Gateway FPM convergence step [{$step}] failed.",
                result: $result,
            );
        }
    }
}
