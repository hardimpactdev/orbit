<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/**
 * One agent command as a step of a Gateway operation. A refusal, and a run without a result, fail the step with its
 * error code; the refusal or runner failure stays the previous exception, so a caller can map a refusal it knows. A
 * timeout keeps its own failure, because a lost result never proves that nothing changed.
 */
final class AgentStep
{
    /**
     * @param  array<string, mixed>  $input
     * @param  string  $label  The kind of step in the message, such as `App development` or `App production`.
     * @return array<string, mixed>
     */
    public static function run(AgentCommandRunner $agents, Node $node, string $command, array $input, string $step, string $errorCode, string $label, ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        try {
            return $agents->run($node, $command, $input, $deadlineSeconds, $output, $binaryInput);
        } catch (AgentCommandRefused $refused) {
            throw self::failed($node, $step, $errorCode, $label, self::output($refused), $refused);
        } catch (AgentRunnerFailure $failure) {
            if ($failure->kind === AgentRunnerFailure::Timeout) {
                throw $failure;
            }

            throw self::failed($node, $step, $errorCode, $label, $failure->result ?? new CommandResult(1, '', $failure->getMessage(), 0, false), $failure);
        }
    }

    /** The refusal that failed a step, when a refusal failed it. */
    public static function refusal(RuntimeConvergenceException $exception): ?AgentCommandRefused
    {
        $previous = $exception->getPrevious();

        return $previous instanceof AgentCommandRefused ? $previous : null;
    }

    /** The step's failure with the command's output, so the Activity of the command keeps it. */
    public static function failed(Node $node, string $step, string $errorCode, string $label, CommandResult $output, ?\Throwable $previous = null): RuntimeConvergenceException
    {
        return new RuntimeConvergenceException($step, $errorCode, "{$label} step [{$step}] failed on node [{$node->name}].", $previous, $output);
    }

    /**
     * A refusal as command output: its code and details on standard output. The exit status is 1, because the step
     * failed although the command ended normally.
     */
    public static function output(AgentCommandRefused $refused): CommandResult
    {
        return new CommandResult(1, json_encode(['code' => $refused->errorCode, 'details' => (object) $refused->details], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", $refused->getMessage(), 0, false);
    }
}
