<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Support\ValidatedData;

/**
 * A deployment in a default Instance's container, from Swamp's native run data. Each Ansible phase writes the run's result file, and
 * the step "Read the container result" prints it whatever happened: the deployed commit, or the refusal's public code. A failed deploy
 * command keeps the shared code of a failed step. `started` says whether the container ran by the end.
 */
final readonly class ContainerDeployOutcome
{
    public const string ResultStep = 'Read the container result';

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function from(array $data): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $steps = ValidatedData::object($result['steps'] ?? []);
        $failed = ValidatedData::stringList(ValidatedData::object($result['summary'] ?? [])['failed_steps'] ?? []);
        $stdout = ValidatedData::nullableString(ValidatedData::object($steps[self::ResultStep] ?? [])['stdout'] ?? null);
        $file = is_string($stdout) ? json_decode(trim($stdout), true) : null;
        $file = is_array($file) ? $file : [];
        // A nested-fleet start's word for what it did with the Club key, kept in the result file by every later phase.
        $payload = ['commit' => null, 'failed_step' => null, 'started' => true, 'club_key' => $file['club_key'] ?? null];
        $status = ValidatedData::nullableString($data['status'] ?? null);
        if ($status === 'succeeded') {
            $payload['commit'] = is_string($file['commit'] ?? null) ? $file['commit'] : null;
        } elseif ($status === 'failed') {
            $step = $failed[0] ?? 'native_execution';
            $payload['failed_step'] = $step;
            // A deployment that failed before the container started leaves its power state as it was.
            $payload['started'] = ! in_array($step, ['native_execution', 'Write the container intent', 'Start the container'], true);
            $code = match (true) {
                str_starts_with($step, 'Run dev deploy steps') => 'deployment.step_failed',
                is_string($file['error_code'] ?? null) && preg_match('/\A[a-z]+\.[a-z_]+\z/', $file['error_code']) === 1 => $file['error_code'],
                default => 'operation.native_failed',
            };
            $data = [...$data, 'error_code' => $code];
        }

        return [$data, $payload];
    }
}
