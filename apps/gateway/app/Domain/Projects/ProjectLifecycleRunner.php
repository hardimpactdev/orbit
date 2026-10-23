<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Shared\StoredValue;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use Throwable;

final readonly class ProjectLifecycleRunner
{
    public function __construct(
        private ProjectLifecycleStepStore $steps,
        private AgentCommandRunner $agents,
        private CommandDeadline $deadline,
        private VpToolManager $vp,
    ) {}

    public function run(Instance $instance, LifecyclePhase $phase): bool
    {
        if ($instance->placedOnAppProd()) {
            return false;
        }

        $instance->loadMissing(['project', 'node']);
        $steps = $this->steps->ordered($instance->project, $phase);

        if ($steps === []) {
            return false;
        }

        $checkout = $instance->checkout_path;

        if ($checkout === '') {
            throw new ResourceOperationException(
                errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                message: 'The Instance has no checkout.',
                status: 422,
            );
        }

        $vpHome = null;

        foreach ($steps as $step) {
            $input = null;
            $budget = 0.0;

            try {
                $vpHome ??= dirname($this->vp->existingBinary($instance->node), 2);
                $timeout = $this->deadline->cap($step->timeoutSeconds + 5.0);
                $budget = $timeout - 5.0;

                if ($budget <= 0.0) {
                    throw $this->deadlineCut($phase, $step, 0.0);
                }

                $program = ['command' => $step->command];
                if (GatewaySetupSteps::owns($step->command)) {
                    if ($phase !== LifecyclePhase::Setup) {
                        throw new ResourceOperationException('instance.teardown_step_failed', "{$step->command} is a setup-only operation.", 422, details: ['step' => $step->name]);
                    }
                    $program = GatewaySetupSteps::program($step->command);
                }

                // Read the seed for each step: converting an old default release layout moves it to the checkout.
                $seed = $phase === LifecyclePhase::Setup ? Instance::query()->whereKey($instance->id)->first(['seed_path', 'seed_commit']) : null;
                // A default records its own checkout as its seed; its setup has no other seed.
                $seeded = $seed !== null && $seed->seed_path !== $checkout;
                $this->runStep($instance, $step, $phase, $program, $timeout, [
                    'VP_HOME' => $vpHome,
                    'ORBIT_SEED_PATH' => $seeded ? ($seed->seed_path ?? '') : '',
                    'ORBIT_SEED_COMMIT' => $seeded ? ($seed->seed_commit ?? '') : '',
                ]);
            } catch (Throwable $exception) {
                if ($exception instanceof ResourceOperationException && $exception->errorCode === 'command.deadline_exceeded' && ($exception->details['outcome'] ?? null) !== 'deadline') {
                    throw $this->deadlineCut($phase, $step, $budget, $exception);
                }

                if ($exception instanceof ResourceOperationException) {
                    if (GatewaySetupSteps::failure($exception)) {
                        throw new ResourceOperationException($exception->errorCode, $exception->getMessage(), $exception->status, details: ['step' => $step->name]);
                    }
                    throw $exception;
                }

                $result = $exception instanceof RuntimeConvergenceException ? $exception->result : null;

                if ($result?->exitCode === 75) {
                    throw new ResourceOperationException(
                        errorCode: 'instance.lifecycle_busy',
                        message: 'The Instance is busy with another lifecycle operation.',
                        status: 409,
                        previous: $exception,
                        details: ['step' => $step->name, 'outcome' => 'busy'],
                    );
                }

                if ($result !== null && in_array($result->exitCode, [126, 127], true)) {
                    $label = $phase === LifecyclePhase::Setup ? 'Setup' : 'Teardown';

                    throw new ResourceOperationException(
                        errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_unavailable' : 'instance.teardown_step_unavailable',
                        message: "{$label} step [{$step->name}] is unavailable on node [{$instance->node->name}]: the command was not found or is not executable (exit {$result->exitCode}).",
                        status: 422,
                        previous: $exception,
                        details: ['step' => $step->name, 'outcome' => 'missing'],
                    );
                }

                // The step ran out of the time the request had left, not out of its own timeout.
                $timedOut = $result?->exitCode === 124
                    || ($exception instanceof AgentRunnerFailure && $exception->kind === AgentRunnerFailure::Timeout);

                if ($timedOut && $budget < $step->timeoutSeconds) {
                    throw $this->deadlineCut($phase, $step, $budget, $exception);
                }

                $confirmed = $result !== null && in_array($result->exitCode, [1, 124], true);

                throw new ResourceOperationException(
                    errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                    message: $phase === LifecyclePhase::Setup ? 'Setup step failed.' : 'Teardown step failed.',
                    status: 422,
                    details: ['step' => $step->name, 'outcome' => $confirmed ? 'failed' : 'unconfirmed'],
                );
            }
        }

        return true;
    }

    /**
     * Runs one step through the agent's `instances.run-lifecycle-step` and fails it with a result in the shape the
     * caller maps: exit 75 for a busy checkout, 124 when the step's own bound stopped it, 126 or 127 for a missing
     * command, and 1 for any other failure. The result keeps the last KiB of the step's standard error.
     *
     * @param  array{command: string}|string  $program  The Project's command, or a Gateway-owned operation.
     * @param  array<string, string>  $environment
     */
    private function runStep(Instance $instance, LifecycleStep $step, LifecyclePhase $phase, array|string $program, float $timeout, array $environment): void
    {
        try {
            $answer = $this->agents->run($instance->node, 'instances.run-lifecycle-step', [
                'checkout' => $instance->checkout_path,
                'program' => $program,
                'step' => $step->name,
                'timeout_seconds' => max(1, (int) floor($timeout - 5.0)),
                'environment' => $environment,
            ], microtime(true) + $timeout);
            $stderr = implode('', array_map(
                static fn (array $chunk): string => StoredValue::string($chunk['value'] ?? null),
                array_filter(is_array($answer['output'] ?? null) ? $answer['output'] : [], static fn (mixed $chunk): bool => is_array($chunk) && ($chunk['stream'] ?? null) === 'stderr'),
            ));
            $exit = StoredValue::integer($answer['exit_code'] ?? 1, 1);
            $result = new CommandResult(
                ($answer['timed_out'] ?? false) === true ? 124 : (in_array($exit, [0, 126, 127], true) ? $exit : 1),
                '',
                substr($stderr, -1024),
                0,
                false,
            );
        } catch (AgentCommandRefused $refusal) {
            $result = new CommandResult($refusal->errorCode === 'instances.lifecycle_busy' ? 75 : 125, '', $refusal->getMessage(), 0, false);
        }

        if (! $result->succeeded()) {
            throw new RuntimeConvergenceException(
                step: $step->name,
                errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                message: "App development step [{$step->name}] failed on node [{$instance->node->name}].",
                result: $result,
            );
        }
    }

    /**
     * A step the request deadline stopped, before or during its run, rather than a step that failed.
     * It marks the deadline as reached, so the cleanup that follows gets the reserve.
     */
    private function deadlineCut(LifecyclePhase $phase, LifecycleStep $step, float $budget, ?Throwable $previous = null): ResourceOperationException
    {
        $label = $phase === LifecyclePhase::Setup ? 'Setup' : 'Teardown';
        $seconds = (int) floor(max(0.0, $budget));
        $message = $seconds === 0
            ? "{$label} step [{$step->name}] did not start: the request deadline has no time left for it."
            : "{$label} step [{$step->name}] was stopped by the request deadline after {$seconds} seconds, before its own {$step->timeoutSeconds}-second timeout.";

        return new ResourceOperationException(
            errorCode: 'command.deadline_exceeded',
            message: $message.' Lower the list\'s step timeouts so the whole list fits one request.',
            status: 504,
            previous: $this->deadline->exceeded($previous),
            details: ['step' => $step->name, 'outcome' => 'deadline'],
        );
    }
}
