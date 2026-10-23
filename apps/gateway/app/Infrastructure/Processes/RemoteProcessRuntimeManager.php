<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\AppDev\ViteProcessLifecycle;
use App\Domain\Logs\LogReadLimit;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Process;
use SensitiveParameter;

/**
 * A Process's systemd unit or Docker container on its Node, through the agent's `processes.*` commands
 * (docs/reference/processes-and-schedules.md). The Gateway renders the unit and the container definition and records
 * a pending systemd activation; the agent checks each artifact's owner marker and makes the change with its rollback.
 */
final readonly class RemoteProcessRuntimeManager implements ProcessRuntimeManager
{
    /** Each `processes.*` refusal and its error code; `{runtime}` is `systemd` or `docker`. */
    private const array ERRORS = [
        'processes.name_collision' => 'process.runtime_name_collision',
        'processes.not_found' => 'process.runtime_not_found',
        'processes.start_failed' => 'process.start_failed',
        'processes.stop_failed' => 'process.stop_failed',
        'processes.restart_failed' => 'process.restart_failed',
        'processes.remove_failed' => 'process.remove_failed',
        'processes.status_failed' => 'process.status_failed',
        'processes.logs_failed' => 'process.logs_failed',
        'processes.converge_failed' => 'process.{runtime}_converge_failed',
        'processes.candidate_invalid' => 'process.{runtime}_candidate_invalid',
        'processes.activation_failed' => 'process.{runtime}_activation_failed',
        'processes.rollback_failed' => 'process.{runtime}_rollback_failed',
        'processes.recovery_required' => 'process.{runtime}_recovery_required',
        'processes.vite_cleanup_failed' => 'vite.environment_cleanup_failed',
    ];

    /** What a failed systemd activation left, by the change it made. */
    private const array RESTORATIONS = [
        'replace' => 'the previous unit was restored',
        'create' => 'the new unit was removed',
        'reload' => 'activation remains pending for retry',
    ];

    private ProcessRuntimeLease $lease;

    public function __construct(
        private ProcessTargetResolver $targets,
        private ManagedUserAccountResolver $accounts,
        private AgentCommandRunner $agents,
        private SystemdProcessRenderer $systemd,
        private DockerProcessRenderer $docker,
        ?ProcessRuntimeLease $lease = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
    }

    public function assertCanStart(#[SensitiveParameter] Process $process): void
    {
        $target = $this->targets->forStart($process);
        $this->assertReleaseAvailable($process, $target, 'start', 'process.start_failed');
    }

    public function converge(#[SensitiveParameter] Process $process): void
    {
        $this->lease->run($process, function (Process $fresh): void {
            $target = $this->targets->forInstallation($fresh);

            $this->assertReleaseAvailable($fresh, $target, 'start', 'process.start_failed');

            if ($fresh->isVpDev()) {
                app(ViteProcessLifecycle::class)->run($fresh, fn () => $this->convergeAndActivateSystemd($fresh, $this->targets->forInstallation($fresh)), fn () => $this->stopUnlocked($fresh, $this->targets->forInspection($fresh)), $fresh->desired_state === DesiredProcessState::Running);

                return;
            }

            match ($fresh->runtime) {
                ProcessRuntime::Systemd => $this->convergeAndActivateSystemd($fresh, $target),
                ProcessRuntime::Docker => $this->convergeDocker($fresh, $target),
            };
        });
    }

    public function start(#[SensitiveParameter] Process $process, bool $explicit = false): void
    {
        $this->lease->run($process, function (Process $fresh) use ($explicit): void {
            $target = $this->targets->forStart($fresh);
            $this->assertReleaseAvailable($fresh, $target, 'start', 'process.start_failed');
            if ($fresh->isVpDev()) {
                app(ViteProcessLifecycle::class)->run($fresh, fn () => $this->startUnlocked($fresh, $this->targets->forStart($fresh)), fn () => $this->stopUnlocked($fresh, $this->targets->forInspection($fresh)), true, explicitStart: $explicit);

                return;
            }
            $this->startUnlocked($fresh, $target);
        });
    }

    public function stop(#[SensitiveParameter] Process $process): void
    {
        $this->lease->run($process, function (Process $fresh): void {
            $this->stopUnlocked($fresh, $this->targets->forProcess($fresh));
        });
    }

    public function restart(#[SensitiveParameter] Process $process): void
    {
        $this->lease->run($process, function (Process $fresh): void {
            $target = $this->targets->forStart($fresh);
            $this->assertReleaseAvailable($fresh, $target, 'restart', 'process.restart_failed');
            if ($fresh->isVpDev()) {
                app(ViteProcessLifecycle::class)->run($fresh, fn () => $this->startUnlocked($fresh, $this->targets->forStart($fresh)), fn () => $this->stopUnlocked($fresh, $this->targets->forInspection($fresh)), true, restart: true);

                return;
            }
            $this->control($fresh, $target, 'restart', 'process.restart_failed');
        });
    }

    public function remove(#[SensitiveParameter] Process $process): void
    {
        // An explicit runtime override removes a retired footprint during an in-place runtime switch.
        // The lease still refreshes the owner and placement before any mutation.
        $retiredRuntime = $process->isDirty('runtime') ? $process->runtime : null;
        $this->lease->run($process, function (Process $fresh) use ($retiredRuntime): void {
            if ($retiredRuntime !== null) {
                $fresh->runtime = $retiredRuntime;
            }
            $this->removeUnlocked($fresh, $this->targets->forRemoval($fresh));
        });
    }

    public function status(#[SensitiveParameter] Process $process): string
    {
        $status = $this->call($process, 'processes.status', $this->artifact($process), 'status', 'process.status_failed');

        return self::text($status, 'state');
    }

    public function logs(#[SensitiveParameter] Process $process, int $lines): string
    {
        // Newest journal entry first, stopped after the byte limit, so the Node does bounded work; a container's
        // standard output and standard error in one stream, in the order it wrote them (ADR 0153).
        $logs = $this->call($process, 'processes.logs', [
            ...$this->artifact($process),
            'lines' => $lines,
            'max_bytes' => LogReadLimit::Bytes,
        ], 'logs', 'process.logs_failed');
        $output = self::text($logs, 'output');

        return $process->runtime === ProcessRuntime::Systemd
            ? LogReadLimit::journalInTimeOrder($output)
            : LogReadLimit::wholeLines($output);
    }

    public function dockerSpecHash(#[SensitiveParameter] Process $process): string
    {
        return $this->docker->specHash($process, $this->targets->forInspection($process));
    }

    private function startUnlocked(#[SensitiveParameter] Process $process, ProcessTarget $target): void
    {
        $this->control($process, $target, 'start', 'process.start_failed');
    }

    private function stopUnlocked(#[SensitiveParameter] Process $process, ProcessTarget $target): void
    {
        $this->control($process, $target, 'stop', 'process.stop_failed');
    }

    /** Starts, stops, or restarts the Process's own unit or container; an absent one is `process.runtime_not_found`. */
    private function control(#[SensitiveParameter] Process $process, ProcessTarget $target, string $operation, string $errorCode): void
    {
        $this->call($process, 'processes.control', [
            'operation' => $operation,
            ...$this->artifact($process),
            'on_demand' => $target->onDemandHostStart,
        ], $operation, $errorCode, $target);
    }

    private function removeUnlocked(#[SensitiveParameter] Process $process, ProcessTarget $target): void
    {
        $vite = $process->runtime === ProcessRuntime::Systemd && $process->isVpDev()
            ? ['path' => SystemdProcessRenderer::viteEnvironmentPath($process->owner_id), 'instance_id' => $process->owner_id]
            : null;

        $this->call($process, 'processes.remove', [...$this->artifact($process), 'vite' => $vite], 'remove', 'process.remove_failed', $target);
    }

    /**
     * Stages the rendered unit, and publishes and activates it when it changed or a published one never finished
     * activating. Completion is cleared before publication, so a lost response leaves the activation pending for the
     * next converge.
     */
    private function convergeAndActivateSystemd(
        #[SensitiveParameter]
        Process $process,
        ProcessTarget $target,
    ): void {
        $rendered = $this->systemd->render(
            $process, $target,
            $target->certificateScope === null ? null : $this->accounts->resolve($target->node),
        );
        $unit = $this->systemd->unitName($process);
        $staged = $this->call($process, 'processes.stage-unit', [
            'process_id' => $process->id,
            'unit' => $unit,
            'content' => $rendered,
        ], 'inspect-unit', 'process.systemd_converge_failed');

        if ($staged['staged'] !== true) {
            $running = $process->desired_state === DesiredProcessState::Running;
            $status = $staged['state'] ?? null;
            if (! $process->systemd_activation_pending
                && ($process->systemd_activation_hash === null || $process->systemd_activation_hash === hash('sha256', $rendered))
                && (($running && $status === 'active') || (! $running && $status === 'inactive'))) {
                return;
            }
            // Published bytes can outlive a failed or interrupted reload or activation.
            $change = 'reload';
        } else {
            $process->systemd_activation_hash = null;
            $process->systemd_activation_pending = true;
            $process->save();
            $change = $staged['previous'] === true ? 'replace' : 'create';
        }

        $activated = $this->call($process, 'processes.activate-unit', [
            'process_id' => $process->id,
            'unit' => $unit,
            'change' => $change,
            'desired' => $process->desired_state->value,
            'on_demand' => $target->onDemandHostStart,
        ], 'activate-unit', 'process.systemd_converge_failed', change: $change);

        $process->systemd_activation_hash = hash('sha256', $rendered);
        $process->systemd_activation_pending = false;
        $process->save();

        if ($activated['backup_left'] === true) {
            throw new ProcessOperationException(
                step: 'remove-unit-backup',
                errorCode: 'process.systemd_converge_failed',
                message: "Process [{$process->name}] runtime step [remove-unit-backup] failed.",
            );
        }
    }

    private function convergeDocker(#[SensitiveParameter] Process $process, ProcessTarget $target): void
    {
        $this->call($process, 'processes.converge-container', [
            'process_id' => $process->id,
            'container' => $this->docker->containerName($process),
            'desired' => $process->desired_state->value,
            'spec' => $this->docker->specHash($process, $target),
            ...$this->docker->containerInput($process, $target),
        ], 'converge-container', 'process.docker_converge_failed');
    }

    private function assertReleaseAvailable(
        #[SensitiveParameter]
        Process $process,
        ProcessTarget $target,
        string $step,
        string $errorCode,
    ): void {
        if (! $target->productionReleaseLayout) {
            return;
        }

        $release = $this->call($process, 'processes.check-release', ['directory' => $target->defaultWorkingDirectory], $step, $errorCode, $target);

        if ($release['available'] !== true) {
            throw new ResourceOperationException(
                errorCode: 'process.release_unavailable',
                message: "Process [{$process->name}] has no selected production release.",
                status: 409,
            );
        }
    }

    /** @return array{process_id: int, runtime: string, name: string} */
    private function artifact(#[SensitiveParameter] Process $process): array
    {
        return [
            'process_id' => $process->id,
            'runtime' => $process->runtime->value,
            'name' => match ($process->runtime) {
                ProcessRuntime::Systemd => $this->systemd->unitName($process),
                ProcessRuntime::Docker => $this->docker->containerName($process),
            },
        ];
    }

    /**
     * Runs one `processes.*` command on the Process's Node. A refusal becomes its error code with the agent's step;
     * a command error the Process does not name, or a run without a result, fails `$step` with `$errorCode`.
     *
     * @param  array<string, mixed>  $input
     * @param  string|null  $change  The change of a systemd activation, which names what a failure restored.
     * @return array<string, mixed>
     */
    private function call(
        #[SensitiveParameter]
        Process $process,
        string $command,
        #[SensitiveParameter]
        array $input,
        string $step,
        string $errorCode,
        ?ProcessTarget $target = null,
        ?string $change = null,
    ): array {
        $target ??= $this->targets->forInspection($process);

        if (! is_string($target->node->wireguard_ip) || $target->node->wireguard_ip === '') {
            throw new ProcessOperationException(
                step: 'ssh',
                errorCode: 'process.wireguard_ip_missing',
                message: "Node [{$target->node->name}] has no WireGuard address.",
            );
        }

        try {
            return $this->agents->run($target->node, $command, $input);
        } catch (AgentCommandRefused $refused) {
            throw $this->refusal($process, $refused, $step, $errorCode, $change);
        } catch (AgentRunnerFailure $failure) {
            if ($failure->kind === AgentRunnerFailure::Timeout) {
                throw $failure;
            }

            throw new ProcessOperationException(
                step: $step,
                errorCode: $errorCode,
                message: "Process [{$process->name}] runtime step [{$step}] failed.",
                result: $failure->result === null ? null : $this->redactDockerEnvironment($process, $failure->result),
                previous: $failure,
            );
        }
    }

    private function refusal(
        #[SensitiveParameter]
        Process $process,
        AgentCommandRefused $refused,
        string $step,
        string $errorCode,
        ?string $change,
    ): ProcessOperationException {
        $code = self::ERRORS[$refused->errorCode] ?? null;

        if ($code === null) {
            return new ProcessOperationException(
                step: $step,
                errorCode: $errorCode,
                message: "Process [{$process->name}] runtime step [{$step}] failed.",
                result: AgentStep::output($refused),
                previous: $refused,
            );
        }

        $details = $refused->details;
        $step = is_string($details['step'] ?? null) ? $details['step'] : $step;
        $output = is_array($details['output'] ?? null) ? $details['output'] : null;
        $result = $output === null ? null : $this->redactDockerEnvironment($process, new CommandResult(
            exitCode: is_int($output['exit_code'] ?? null) ? $output['exit_code'] : 1,
            stdout: self::text($output, 'stdout'),
            stderr: self::text($output, 'stderr'),
            durationMs: 0,
            truncated: false,
        ));

        return new ProcessOperationException(
            step: $step,
            errorCode: str_replace('{runtime}', $process->runtime->value, $code),
            message: $this->message($process, $refused->errorCode, $step, $details, $change),
            result: $result,
            previous: $refused,
        );
    }

    /** @param  array<string, mixed>  $details */
    private function message(Process $process, string $code, string $step, array $details, ?string $change): string
    {
        $artifact = self::text($details, 'artifact');
        $docker = $process->runtime === ProcessRuntime::Docker;
        $restoration = $docker ? 'the previous container was restored' : self::RESTORATIONS[$change ?? 'reload'];

        return match (true) {
            $code === 'processes.name_collision' => ($docker ? "Docker container [{$artifact}]" : "Systemd unit [{$artifact}]").' is not owned by this process.',
            $code === 'processes.not_found' => "Process [{$process->name}] has no native runtime artifact.",
            $code === 'processes.candidate_invalid' && ! $docker => "Process [{$process->name}] systemd candidate validation failed.",
            $code === 'processes.candidate_invalid' => ($step === 'verify-container' ? 'Docker container' : 'Docker candidate')." [{$artifact}] does not match the desired process specification.",
            $code === 'processes.activation_failed' && $docker => "Docker process [{$process->name}] activation failed; the previous container was restored.",
            $code === 'processes.activation_failed' && $change !== 'reload' => "Process [{$process->name}] systemd activation failed; the previous unit was restored.",
            in_array($code, ['processes.start_failed', 'processes.stop_failed'], true) && ($details['restored'] ?? false) === true => sprintf('Process [%s] failed to %s; %s.', $process->name, $code === 'processes.start_failed' ? 'start' : 'stop', $restoration),
            $code === 'processes.recovery_required' && ($details['reason'] ?? null) === 'ambiguous_rollback' => "Docker process [{$process->name}] has ambiguous rollback state.",
            $code === 'processes.recovery_required' && ($details['reason'] ?? null) === 'ambiguous_candidate' => "Docker process [{$process->name}] has ambiguous candidate and rollback state.",
            $code === 'processes.recovery_required' && ($details['reason'] ?? null) === 'no_canonical' => "Docker process [{$process->name}] has no canonical runtime after recovery.",
            default => "Process [{$process->name}] runtime step [{$step}] failed.",
        };
    }

    /** @param  array<mixed>  $values */
    private static function text(array $values, string $key): string
    {
        return is_string($values[$key] ?? null) ? $values[$key] : '';
    }

    private function redactDockerEnvironment(
        #[SensitiveParameter]
        Process $process,
        #[SensitiveParameter]
        CommandResult $result,
    ): CommandResult {
        if ($process->runtime !== ProcessRuntime::Docker) {
            return $result;
        }

        $environment = $process->runtime_config['environment'] ?? null;

        if (! is_array($environment)) {
            return $result;
        }

        $values = [];

        foreach ($environment as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $values[] = $value;
        }

        $values = array_values(array_unique($values));
        usort($values, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return new CommandResult(
            exitCode: $result->exitCode,
            stdout: str_replace(search: $values, replace: '[REDACTED]', subject: $result->stdout),
            stderr: str_replace(search: $values, replace: '[REDACTED]', subject: $result->stderr),
            durationMs: $result->durationMs,
            truncated: $result->truncated,
        );
    }
}
