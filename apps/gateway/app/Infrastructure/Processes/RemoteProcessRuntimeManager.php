<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Logs\LogReadLimit;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
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

/** Reads runtime state in Orbit; writes admit and await a Swamp Operation. */
final readonly class RemoteProcessRuntimeManager implements ProcessRuntimeManager
{
    /** Each `process.*` refusal and its error code; `{runtime}` is `systemd` or `docker`. */
    private const array ERRORS = [
        'process.name_collision' => 'process.runtime_name_collision',
        'process.not_found' => 'process.runtime_not_found',
        'process.start_failed' => 'process.start_failed',
        'process.stop_failed' => 'process.stop_failed',
        'process.restart_failed' => 'process.restart_failed',
        'process.remove_failed' => 'process.remove_failed',
        'process.status_failed' => 'process.status_failed',
        'process.logs_failed' => 'process.logs_failed',
        'process.converge_failed' => 'process.{runtime}_converge_failed',
        'process.candidate_invalid' => 'process.{runtime}_candidate_invalid',
        'process.activation_failed' => 'process.{runtime}_activation_failed',
        'process.rollback_failed' => 'process.{runtime}_rollback_failed',
        'process.recovery_required' => 'process.{runtime}_recovery_required',
        'process.vite_cleanup_failed' => 'vite.environment_cleanup_failed',
    ];

    /** What a failed systemd activation left, by the change it made. */
    private const array RESTORATIONS = [
        'replace' => 'the previous unit was restored',
        'create' => 'the new unit was removed',
        'reload' => 'activation remains pending for retry',
    ];

    public function __construct(
        private ProcessTargetResolver $targets,
        private AgentCommandRunner $agents,
        private SystemdProcessRenderer $systemd,
        private DockerProcessRenderer $docker,
    ) {}

    public function assertCanStart(#[SensitiveParameter] Process $process): void
    {
        $target = $this->targets->forStart($process);
        $this->assertReleaseAvailable($process, $target, 'start', 'process.start_failed');
    }

    public function converge(#[SensitiveParameter] Process $process): void
    {
        $operation = app(ProcessOperationAdmission::class)->execute($process, 'create', preserveRecord: false);
        app(AwaitOperation::class)->execute($operation);
    }

    public function start(#[SensitiveParameter] Process $process, bool $explicit = false): void
    {
        $operation = app(ProcessOperationAdmission::class)->execute($process, 'start', preserveRecord: false);
        app(AwaitOperation::class)->execute($operation);
    }

    public function stop(#[SensitiveParameter] Process $process): void
    {
        $operation = app(ProcessOperationAdmission::class)->execute($process, 'stop', preserveRecord: false);
        app(AwaitOperation::class)->execute($operation);
    }

    public function restart(#[SensitiveParameter] Process $process): void
    {
        $operation = app(ProcessOperationAdmission::class)->execute($process, 'restart', preserveRecord: false);
        app(AwaitOperation::class)->execute($operation);
    }

    public function remove(#[SensitiveParameter] Process $process): void
    {
        $operation = app(ProcessOperationAdmission::class)->execute($process, 'destroy', preserveRecord: true);
        app(AwaitOperation::class)->execute($operation);
    }

    public function status(#[SensitiveParameter] Process $process): string
    {
        $status = $this->call($process, 'process.status', $this->artifact($process), 'status', 'process.status_failed');

        return self::text($status, 'state');
    }

    public function logs(#[SensitiveParameter] Process $process, int $lines): string
    {
        // Newest journal entry first, stopped after the byte limit, so the Node does bounded work; a container's
        // standard output and standard error in one stream, in the order it wrote them (ADR 0153).
        $logs = $this->call($process, 'process.logs', [
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

        $release = $this->call($process, 'process.check-release', ['directory' => $target->defaultWorkingDirectory], $step, $errorCode, $target);

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
     * Runs one `process.*` command on the Process's Node. A refusal becomes its error code with the agent's step;
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
            $code === 'process.name_collision' => ($docker ? "Docker container [{$artifact}]" : "Systemd unit [{$artifact}]").' is not owned by this process.',
            $code === 'process.not_found' => "Process [{$process->name}] has no native runtime artifact.",
            $code === 'process.candidate_invalid' && ! $docker => "Process [{$process->name}] systemd candidate validation failed.",
            $code === 'process.candidate_invalid' => ($step === 'verify-container' ? 'Docker container' : 'Docker candidate')." [{$artifact}] does not match the desired process specification.",
            $code === 'process.activation_failed' && $docker => "Docker process [{$process->name}] activation failed; the previous container was restored.",
            $code === 'process.activation_failed' && $change !== 'reload' => "Process [{$process->name}] systemd activation failed; the previous unit was restored.",
            in_array($code, ['process.start_failed', 'process.stop_failed'], true) && ($details['restored'] ?? false) === true => sprintf('Process [%s] failed to %s; %s.', $process->name, $code === 'process.start_failed' ? 'start' : 'stop', $restoration),
            $code === 'process.recovery_required' && ($details['reason'] ?? null) === 'ambiguous_rollback' => "Docker process [{$process->name}] has ambiguous rollback state.",
            $code === 'process.recovery_required' && ($details['reason'] ?? null) === 'ambiguous_candidate' => "Docker process [{$process->name}] has ambiguous candidate and rollback state.",
            $code === 'process.recovery_required' && ($details['reason'] ?? null) === 'no_canonical' => "Docker process [{$process->name}] has no canonical runtime after recovery.",
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
