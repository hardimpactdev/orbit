<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Extensions\ConfigValue;
use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\SystemdVpnOrderingDropIn;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use Illuminate\Container\Attributes\Tag;

/**
 * Converges the Gateway PHP-FPM pool and the contributed start hooks ({@see GatewayServiceStartHook}). The container
 * binds it once, with the contributed hooks, for Gateway web convergence and the release handoff.
 */
final readonly class NativeGatewayFpmConverger
{
    private const string LIVE_POOL = '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf';

    /** Where each start hook program lives. A hook name ends in `-start`, which no core path in it does. */
    public const string START_HOOK_DIRECTORY = '/etc/orbit';

    public const string START_HOOK_DROP_INS = '/etc/systemd/system/php8.5-fpm.service.d';

    /** The hooks core installed, one `<name> <drop-in>` line each. Core removes only the hooks it lists. */
    public const string START_HOOK_MANIFEST = '/etc/orbit/php-fpm-start-hooks';

    public const string START_HOOK_NAME = '/\A[a-z0-9]+(?:-[a-z0-9]+)*-start\z/D';

    public const string START_HOOK_DROP_IN = '/\Aorbit-[a-z0-9]+(?:-[a-z0-9]+)*\.conf\z/D';

    private const string START_HOOKS_PROGRAM = 'gateway.install-start-hooks';

    /** @param iterable<GatewayServiceStartHook> $startHooks */
    public function __construct(
        private ProcessRunner $processes,
        private RemoteProgramCatalog $programs,
        #[Tag(GatewayServiceStartHook::class)] private iterable $startHooks,
    ) {}

    /**
     * Installs the pool and the start hooks, runs each hook, and activates the pool. A hook that breaks the contract
     * stops convergence before anything is installed.
     */
    public function converge(string $generatedPool): void
    {
        $this->validateStartHooks();
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

        $this->installStartHooks();
        // A running distro FPM service can activate the Gateway pool with a reload, which skips ExecStartPre.
        foreach ($this->startHooks as $hook) {
            $this->run(
                step: 'gateway-fpm-start-hook',
                errorCode: 'gateway.fpm_start_failed',
                arguments: ['sudo', self::START_HOOK_DIRECTORY.'/'.$hook->name()],
                message: "Gateway FPM start hook [{$hook->name()}] failed.",
            );
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

    /**
     * Installs each contributed hook with `gateway.install-start-hooks`, which changes only files whose bytes differ.
     * It removes the hooks the manifest lists that are no longer contributed. A hook takes effect at the next PHP-FPM
     * start; only {@see converge()} runs it.
     */
    public function installStartHooks(): void
    {
        $this->validateStartHooks();
        $checkout = config()->string('orbit.gateway_checkout');
        $home = config()->string('orbit.home');
        $arguments = ['sudo', 'bash', '-seu', '--', self::START_HOOK_DIRECTORY, self::START_HOOK_DROP_INS, self::START_HOOK_MANIFEST];
        foreach ($this->startHooks as $hook) {
            array_push($arguments, $hook->name(), $hook->dropIn(), $hook->description(), $hook->renderHook($checkout, $home));
        }
        $program = $this->programs->program(self::START_HOOKS_PROGRAM);
        $result = $this->processes->run(new ProcessInvocation(
            arguments: $arguments,
            timeout: (float) $program->timeoutSeconds,
            input: $program->source,
        ));

        if (! $result->succeeded()) {
            // The program's last marker names the hook it was working on. An empty one means a step for every hook.
            preg_match_all('/^orbit-start-hook=(\S*)$/m', $result->stderr, $markers);
            $hook = end($markers[1]);

            throw new NodeProvisioningException(
                step: 'gateway-fpm-start-hook-install',
                errorCode: 'gateway.fpm_config_install_failed',
                message: is_string($hook) && $hook !== '' ? "Gateway FPM start hook [{$hook}] could not be installed or removed." : 'The Gateway FPM start hooks could not be installed.',
                result: $result,
            );
        }
    }

    /**
     * Each hook names a program in /etc/orbit that ends in `-start`, so it cannot take a core path, and an `orbit-*.conf`
     * drop-in that systemd reads. The two names are independent. The description is one printable line, so it cannot add a directive to the drop-in.
     * No two hooks share a program or a drop-in.
     *
     * @throws NodeProvisioningException
     */
    private function validateStartHooks(): void
    {
        $names = [];
        $dropIns = [];
        foreach ($this->startHooks as $hook) {
            $name = $hook->name();
            $dropIn = $hook->dropIn();
            $problem = match (true) {
                preg_match(self::START_HOOK_NAME, $name) !== 1 => 'a program name that does not end in -start',
                preg_match(self::START_HOOK_DROP_IN, $dropIn) !== 1 => 'a drop-in that is not orbit-*.conf',
                $dropIn === SystemdVpnOrderingDropIn::FILE_NAME => "the drop-in [{$dropIn}] that core writes",
                ! ConfigValue::printableLine($hook->description()) => 'a description that is not one printable line',
                isset($names[$name]) => "the program name [{$name}] of another hook",
                isset($dropIns[$dropIn]) => "the drop-in [{$dropIn}] of another hook",
                default => null,
            };
            if ($problem !== null) {
                throw new NodeProvisioningException(
                    step: 'gateway-fpm-start-hook-validate',
                    errorCode: 'gateway.fpm_config_invalid',
                    message: 'Gateway FPM start hook ['.$hook::class."] has {$problem}.",
                );
            }
            $names[$name] = true;
            $dropIns[$dropIn] = true;
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
