<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Logs\LogRedactor;
use Carbon\CarbonImmutable;
use Throwable;

/** Public native failure diagnostics, shared by event projection and Operation reads. */
final readonly class NativeStepFailure
{
    public const int MaximumBytes = 4096;

    public function __construct(private LogRedactor $redactor) {}

    /** @param array<string, mixed> $run
     * @return array{failed_step:string,detail:string,stderr_recorded:bool}|null
     */
    public function fromRun(array $run): ?array
    {
        if (! in_array($run['status'] ?? null, ['failed', 'interrupted'], true)) {
            return null;
        }
        $candidates = [];
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
                if (! is_string($detail) || $detail === '') {
                    $detail = 'The native step failed without stderr.';
                }

                $detail = $this->text($detail);

                $completed = false;
                if (is_string($step['completedAt'] ?? null)) {
                    try {
                        $completed = (float) CarbonImmutable::parse($step['completedAt'])->format('U.u');
                    } catch (Throwable) {
                        $completed = false;
                    }
                }
                $candidates[] = ['completed' => $completed, 'failure' => [
                    'failed_step' => $this->text($name, 255, false), 'detail' => $detail !== '' ? $detail : 'The incomplete native diagnostic line was omitted.',
                    'stderr_recorded' => ($outputs['stderr_recorded'] ?? is_string($outputs['stderr'] ?? null)) === true]];
            }
        }

        if ($candidates !== [] && ! in_array(false, array_column($candidates, 'completed'), true)) {
            usort($candidates, static fn (array $left, array $right): int => $left['completed'] <=> $right['completed']);
        }

        return $candidates[0]['failure'] ?? null;
    }

    private function text(string $value, int $maximum = self::MaximumBytes, bool $tail = true): string
    {
        $tokens = [];
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
        $result = mb_strcut($value, -$maximum, $maximum, 'UTF-8');
        if (strlen($value) > $maximum && $value[strlen($value) - $maximum - 1] !== "\n") {
            $newline = strpos($result, "\n");
            $result = $newline === false ? '' : substr($result, $newline + 1);
        }

        return $this->redactor->redact($result, $tokens);
    }
}
