<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Infrastructure\AgentCommands\PiArtifactDelivery;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\Processes\CommandDeadline;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;

/** Keeps image build intent and resume state; the agent owns each durable guest phase. */
final readonly class RecipeImagePrograms
{
    public const array Phases = ['apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi'];

    public const int PhaseSeconds = 1440;

    public const int ObservationGraceSeconds = 60;

    public function __construct(
        private CommandDeadline $deadline,
        private GuestAgentCommandRunner $agents,
        private AgentDelivery $delivery,
        private PiArtifactDelivery $pi,
    ) {}

    public function apply(AgentGuest $guest, MachineRecipe $recipe, string $profile, string $phase): void
    {
        $state = [];
        while (! $this->poll($guest, $recipe, $profile, $phase, 'upcloud', $state)) {
            Sleep::for(5)->seconds();
        }
    }

    /**
     * @param  array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}  $state
     * @param  (Closure(array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}): void)|null  $save
     */
    public function poll(AgentGuest $guest, MachineRecipe $recipe, string $profile, string $phase, string $execution, array &$state, ?Closure $save = null, int $attempt = 1): bool
    {
        (new RecipeImageInput($this->delivery))->assertDelivery($guest, $recipe);
        if (! isset($state['observed_at'])) {
            $state['observed_at'] = microtime(true);
            $save?->__invoke($state);
        }
        $observedAt = $state['observed_at'];
        $unknown = null;
        try {
            if ($this->deadline->within(self::PhaseSeconds, function () use ($guest, $recipe, $profile, $phase, $execution, &$state, $save, $attempt): bool {
                return $this->observe($guest, $recipe, $profile, $phase, $execution, $state, $save, $attempt);
            })) {
                return true;
            }
        } catch (TaskVmException $error) {
            if ($error->errorCode !== TaskVmError::ImageChannelLost->value) {
                throw $error;
            }
            $unknown = $error;
            Log::warning($error->getMessage(), ['phase' => $profile.':'.$phase]);
        }
        if (microtime(true) - $observedAt > self::PhaseSeconds + self::ObservationGraceSeconds) {
            throw $unknown ?? new TaskVmException(TaskVmError::ImageChannelLost, "Recipe image phase [{$profile}:{$phase}] could not be observed within its runtime bound. The guest phase outcome is unknown.");
        }

        return false;
    }

    /**
     * @param  array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}  $state
     * @param  (Closure(array{observed_at?: float, boot_id?: string, attempt?: int, restart_from?: string}): void)|null  $save
     */
    private function observe(AgentGuest $guest, MachineRecipe $recipe, string $profile, string $phase, string $execution, array &$state, ?Closure $save, int $attempt): bool
    {
        $key = ['identity' => $recipe->identity, 'profile' => $profile, 'phase' => $phase, 'execution' => $execution];
        $status = $this->status($this->request($guest, 'image.inspect-phase', ['key' => $key, 'boot_id' => $state['boot_id'] ?? null]));
        if ($status['state'] !== 'missing' && ($state['attempt'] ?? $attempt) < $attempt && ! isset($state['restart_from'])) {
            if ($status['state'] === 'failed') {
                $state['restart_from'] = $status['invocation_id'] ?? throw new RuntimeException('A failed phase has no invocation ID.');
            } else {
                $state['attempt'] = $attempt;
            }
            $save?->__invoke($state);
        }
        if ($status['state'] !== 'missing' && isset($state['restart_from'])) {
            if ($status['invocation_id'] === $state['restart_from']) {
                $status = $this->status($this->request($guest, 'image.restart-phase', ['key' => $key, 'boot_id' => $status['boot_id'], 'invocation_id' => $state['restart_from']]));
            }
            unset($state['restart_from']);
            $state['attempt'] = $attempt;
            $save?->__invoke($state);
        }
        if ($status['state'] === 'missing') {
            $payload = $this->payload($guest, $recipe, $profile);
            if ($phase === 'pi') {
                if ($payload['pi_sha256'] === null) {
                    return true;
                }
                $pin = $recipe->tools['pi']->artifacts[$payload['recipe']['architecture']];
                $this->deliverPi($guest, config()->string('orbit.home').'/sandbox-artifacts/'.$pin->location, $pin->digest);
            }
            if (! isset($state['boot_id'])) {
                $state['observed_at'] = microtime(true);
            }
            $state['boot_id'] = $status['boot_id'];
            $state['attempt'] = $attempt;
            $save?->__invoke($state);
            $status = $this->status($this->request($guest, 'image.start-phase', ['key' => $key, 'boot_id' => $state['boot_id'], 'recipe' => $payload]));
        }
        if ($status['state'] === 'running') {
            return false;
        }
        if ($status['state'] === 'complete') {
            return true;
        }
        if ($status['state'] === 'missing') {
            throw new TaskVmException(TaskVmError::ImageChannelLost, 'The image phase start has not been observed. The guest outcome is unknown.');
        }
        $detail = $status['log_tail'];
        throw new TaskVmException(TaskVmError::ImageBuildFailed, "Recipe image phase [{$profile}:{$phase}] {$status['reason']}. Unit: {$status['unit']}\n".mb_substr($detail, -2000),
            imageFailure: ['phase' => $profile.':'.$phase, 'exit_code' => $status['exit_code'], 'stderr' => $detail]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{boot_id: string, unit: string, state: string, invocation_id: string|null, exit_code: int|null, reason: string|null, log_tail: string}
     */
    private function status(array $result): array
    {
        if (! is_string($result['boot_id'] ?? null) || ! is_string($result['unit'] ?? null)
            || ! in_array($result['state'] ?? null, ['missing', 'running', 'complete', 'failed'], true)
            || ! is_string($result['log_tail'] ?? null)
            || (isset($result['invocation_id']) && ! is_string($result['invocation_id']))
            || (! isset($result['invocation_id']) && $result['state'] !== 'missing')
            || (isset($result['exit_code']) && ! is_int($result['exit_code']))
            || (isset($result['reason']) && ! is_string($result['reason']))) {
            throw new TaskVmException(TaskVmError::ImageChannelLost, 'The agent returned an invalid phase observation. The guest outcome is unknown.');
        }

        return ['boot_id' => $result['boot_id'], 'unit' => $result['unit'], 'state' => $result['state'],
            'invocation_id' => $result['invocation_id'] ?? null, 'exit_code' => $result['exit_code'] ?? null,
            'reason' => $result['reason'] ?? null, 'log_tail' => $result['log_tail']];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function request(AgentGuest $guest, string $command, array $input): array
    {
        try {
            return $this->agents->runGuest($guest, $command, $input, $this->deadline->cap(self::PhaseSeconds));
        } catch (AgentCommandRefused $error) {
            if ($error->errorCode === 'task_vm.image_channel_lost') {
                throw new TaskVmException(TaskVmError::ImageChannelLost, $error->getMessage(), previous: $error);
            }
            if (! in_array($error->errorCode, ['task_vm.image_build_failed', 'task_vm.image_builder_rebooted'], true)) {
                throw $error;
            }
            $reason = is_string($error->details['reason'] ?? null) ? $error->details['reason'] : $error->getMessage();
            if ($command === 'image.verify' && $error->errorCode === 'task_vm.image_build_failed') {
                $exit = is_int($error->details['exit_code'] ?? null) ? $error->details['exit_code'] : null;
                $diagnostic = new CommandActivityInputSanitizer()->redactText(is_string($error->details['stderr'] ?? null) ? $error->details['stderr'] : '');
                $reason = new CommandActivityInputSanitizer()->redactText($reason);
                $detail = mb_substr(trim($reason."\n".$diagnostic), -8000);
                throw new TaskVmException(TaskVmError::ImageBuildFailed, 'Recipe image verification failed'.($exit === null ? '' : ' with exit '.$exit).".\n".mb_substr($detail, -2000), previous: $error,
                    imageFailure: ['phase' => 'base:verify', 'exit_code' => $exit, 'stderr' => $detail]);
            }
            throw new TaskVmException(TaskVmError::ImageBuildFailed, $reason, previous: $error);
        } catch (AgentRunnerFailure $error) {
            throw new TaskVmException(TaskVmError::ImageChannelLost, 'Image command result was lost or unreadable: '.$error->getMessage().' The guest phase outcome is unknown.', previous: $error);
        } catch (ResourceOperationException $error) {
            if ($error->errorCode !== 'command.deadline_exceeded') {
                throw $error;
            }
            throw new TaskVmException(TaskVmError::ImageChannelLost, 'The image control delivery exhausted its budget. The guest phase outcome is unknown.', previous: $error);
        }
    }

    public function verify(AgentGuest $guest, MachineRecipe $recipe, string $profile): void
    {
        $result = $this->request($guest, 'image.verify', $this->payload($guest, $recipe, $profile));
        if (($result['ready'] ?? null) !== true) {
            throw new TaskVmException(TaskVmError::ImageBuildFailed, 'Image verification did not confirm the recipe.');
        }
    }

    private function deliverPi(AgentGuest $guest, string $file, string $digest): void
    {
        try {
            $this->pi->deliver($guest, $file, $digest);
        } catch (AgentRunnerFailure $error) {
            throw new TaskVmException(TaskVmError::ImageChannelLost, 'Pi artifact delivery could not be observed. '.$error->getMessage(), previous: $error);
        } catch (ResourceOperationException $error) {
            if ($error->errorCode !== 'command.deadline_exceeded') {
                throw $error;
            }
            throw new TaskVmException(TaskVmError::ImageChannelLost, 'Pi artifact delivery exhausted the image control budget.', previous: $error);
        } catch (AgentCommandRefused $error) {
            if (! str_starts_with($error->errorCode, 'pi.artifact_')) {
                throw $error;
            }
            throw new TaskVmException(TaskVmError::ImageBuildFailed, $error->getMessage(), previous: $error);
        } catch (RuntimeException $error) {
            throw new TaskVmException(TaskVmError::ImageBuildFailed, $error->getMessage(), previous: $error);
        }
    }

    /** @return array{identity: string, recipe: array{user: string, snapshot: string, architecture: string, tools: array<string, mixed>|object, vp_lock: string|null, git_packages: list<string>, artifact_mirror: string}, rust: array<string, mixed>|object, php: array<string, mixed>|null, agent_sha256: string, pi_sha256: string|null} */
    private function payload(AgentGuest $guest, MachineRecipe $recipe, string $profile): array
    {
        return (new RecipeImageInput($this->delivery))->payload($guest, $recipe, $profile);
    }
}
