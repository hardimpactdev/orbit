<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Logs\LogRedactor;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;

/** Public native failure diagnostics, shared by event projection and Operation reads. */
final readonly class NativeStepFailure
{
    public const int MaximumBytes = 4096;

    public function __construct(private LogRedactor $redactor) {}

    /** @param array<string, mixed> $run
     * @return array{failed_step:string,detail:string,stderr_recorded:bool}|null
     */
    public function fromRun(array $run, Operation $operation, bool $includeCancelled = false): ?array
    {
        if (! in_array($run['status'] ?? null, ['failed', 'interrupted'], true)
            && ! ($includeCancelled && ($run['status'] ?? null) === 'cancelled')) {
            return null;
        }
        $candidates = [];
        $target = $this->target($operation);
        $values = $target === null ? [] : $this->redactor->valuesFor($target);
        foreach (is_array($run['jobs'] ?? null) ? $run['jobs'] : [] as $job) {
            if (! is_array($job)) {
                continue;
            }
            $steps = is_array($job['steps'] ?? null) ? $job['steps'] : [];
            foreach ($steps as $step) {
                if (! is_array($step) || ($step['status'] ?? null) !== 'failed' || ($step['allowedFailure'] ?? false) === true || ! is_string($step['name'] ?? null)) {
                    continue;
                }
                $outputs = is_array($step['outputs'] ?? null) ? $step['outputs'] : [];
                $name = $step['name'];
                $detail = is_string($outputs['stderr'] ?? null) && $outputs['stderr'] !== '' ? $outputs['stderr'] : ($step['error'] ?? '');
                $seconds = ($outputs['exitCode'] ?? null) === 124 ? $this->timeoutSeconds($outputs['command'] ?? null) : null;
                if ($seconds !== null) {
                    $detail = $name.' timed out after '.$seconds.'s';
                }
                if (! is_string($detail) || $detail === '') {
                    $detail = 'The native step failed without stderr.';
                }

                if ($target === null && in_array($operation->target_type, ['instance', 'process'], true)) {
                    $detail = 'The native step failed; its target diagnostic context is unavailable.';
                }
                $detail = $this->text($detail, $values);

                $completed = NativeRunTimestamp::parse($step['completedAt'] ?? null)?->format('U.u');
                $candidates[] = ['completed' => $completed, 'failure' => [
                    'failed_step' => $this->text($name, $values, 255, false), 'detail' => $detail !== '' ? $detail : 'The incomplete native diagnostic line was omitted.',
                    'stderr_recorded' => ($outputs['stderr_recorded'] ?? is_string($outputs['stderr'] ?? null)) === true]];
            }
        }

        if ($candidates !== [] && ! in_array(null, array_column($candidates, 'completed'), true)) {
            usort($candidates, static fn (array $left, array $right): int => $left['completed'] <=> $right['completed']);
        }

        return $candidates[0]['failure'] ?? null;
    }

    /**
     * A failed step's stderr that its phase wrote into the run's result file, because Swamp keeps no stderr of a failed command
     * step. It is redacted whole, then bounded to 4096 bytes: a longer one keeps its first line's head, which names the failed task,
     * and the tail of its last whole lines, cut between UTF-8 characters only.
     *
     * @return array{failed_step:string,detail:string,stderr_recorded:true}
     */
    public function written(string $failedStep, string $stderr, Operation $operation): array
    {
        $target = $this->target($operation);
        $values = $target === null ? [] : $this->redactor->valuesFor($target);
        $whole = $this->text($stderr, $values, PHP_INT_MAX, false);
        $detail = $whole;
        if (strlen($whole) > self::MaximumBytes) {
            $head = mb_strcut($whole, 0, 1000, 'UTF-8');
            $tail = $this->text($whole, $values, self::MaximumBytes - strlen($head) - 5);
            $detail = $head."\n...\n".$tail;
        }

        return ['failed_step' => $this->text($failedStep, $values, 255, false),
            'detail' => $detail !== '' ? $detail : 'The incomplete native diagnostic line was omitted.', 'stderr_recorded' => true];
    }

    private function target(Operation $operation): Instance|Process|null
    {
        return match ($operation->target_type) {
            'instance' => Instance::query()->find($operation->target_id),
            'process' => Process::query()->find($operation->target_id),
            default => null,
        };
    }

    private function timeoutSeconds(mixed $command): ?string
    {
        if (is_string($command) && preg_match('/\Atimeout[ \t]+([1-9][0-9]*)(?:[ \t]|$)/D', $command, $matches) === 1) {
            return $matches[1];
        }
        if (is_array($command) && ($command[0] ?? null) === 'timeout' && is_string($command[1] ?? null)
            && preg_match('/\A[1-9][0-9]*\z/D', $command[1]) === 1) {
            return $command[1];
        }

        return null;
    }

    /** @param list<string> $values */
    private function text(string $value, array $values, int $maximum = self::MaximumBytes, bool $tail = true): string
    {
        $tokens = $values;
        foreach ([config('swamp.run_token'), config('swamp.read_token')] as $token) {
            if (is_string($token) && $token !== '') {
                $tokens[] = $token;
            }
        }
        $value = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value) ?? '';

        $value = $this->redactor->redact($value, $tokens);
        if (! $tail) {
            return mb_strcut($value, 0, $maximum, 'UTF-8');
        }
        $result = substr($value, -$maximum);
        if (strlen($value) > $maximum && $value[strlen($value) - $maximum - 1] !== "\n") {
            $newline = strpos($result, "\n");
            $result = $newline === false ? '' : substr($result, $newline + 1);
        }

        return $this->redactor->redact($result, $tokens);
    }
}
