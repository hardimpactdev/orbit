<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use Illuminate\Support\Sleep;

/** Boot deadlines and public error mapping; Rust owns guest observations. */
final readonly class GuestBootstrap
{
    public function __construct(private GuestAgentCommandRunner $agents) {}

    public function ready(AgentGuest $guest, string $subject, ?float $seconds = null): bool
    {
        try {
            $result = $this->request($guest, $subject, 'guest.cloud-init', $seconds);
        } catch (AgentRunnerFailure $error) {
            if (! self::pending($error)) {
                throw $error;
            }

            return false;
        }
        if (! is_bool($result['ready'] ?? null)) {
            throw self::invalid($subject, 'the readiness result is missing');
        }

        return $result['ready'];
    }

    public function fingerprint(AgentGuest $guest, string $subject): string
    {
        $result = $this->request($guest, $subject, 'guest.fingerprint');
        $fingerprint = $result['fingerprint'] ?? null;
        if (! is_string($fingerprint)) {
            throw self::invalid($subject, 'the fingerprint result is missing');
        }

        return $fingerprint;
    }

    /** Command mode is installed before the first image recipe phase, including a stock source image. */
    public function awaitReady(AgentGuest $guest, string $subject, int $seconds): void
    {
        $deadline = now()->getTimestamp() + $seconds;
        $installed = false;
        while (now()->getTimestamp() < $deadline) {
            try {
                if (! $installed) {
                    $this->agents->installGuest($guest);
                    $installed = true;
                }
                if ($this->ready($guest, $subject, max(1, $deadline - now()->getTimestamp()))) {
                    return;
                }
            } catch (AgentRunnerFailure $error) {
                if (! self::pending($error)) {
                    throw $error;
                }
            }
            $remaining = $deadline - now()->getTimestamp();
            if ($remaining > 0) {
                Sleep::for(min(3, $remaining))->seconds();
            }
        }
        throw new TaskVmException('task_vm.bootstrap_timeout', "Cloud-init on {$subject} was not done {$seconds} seconds after launch.", 504);
    }

    private static function pending(AgentRunnerFailure $error): bool
    {
        return $error->kind === AgentRunnerFailure::Transport && $error->result !== null
            && ! $error->result->succeeded() && trim($error->result->stdout) === '' && ! $error->result->truncated;
    }

    /** @return array<string, mixed> */
    private function request(AgentGuest $guest, string $subject, string $command, ?float $seconds = null): array
    {
        try {
            return $this->agents->runGuest($guest, $command, deadlineSeconds: $seconds);
        } catch (AgentCommandRefused $error) {
            $details = $error->details;
            throw match ($error->errorCode) {
                'task_vm.bootstrap_failed' => new TaskVmException($error->errorCode, "Cloud-init failed on {$subject}: ".(is_string($details['errors'] ?? null) ? $details['errors'] : $error->getMessage()), 502),
                'task_vm.invalid_host_output' => self::invalid($subject, is_string($details['reason'] ?? null) ? $details['reason'] : $error->getMessage()),
                'task_vm.host_command_failed' => new TaskVmException($error->errorCode, "A guest command on {$subject} failed: ".(is_string($details['stderr'] ?? null) ? $details['stderr'] : $error->getMessage()), 502),
                default => $error,
            };
        }
    }

    private static function invalid(string $subject, string $reason): TaskVmException
    {
        return new TaskVmException('task_vm.invalid_host_output', ucfirst($subject)." returned invalid guest output: {$reason}.", 502);
    }
}
