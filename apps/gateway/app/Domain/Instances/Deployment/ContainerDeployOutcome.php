<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Support\ValidatedData;

/**
 * A deployment in a default Instance's container, from Swamp's native run data. Each Ansible phase writes the run's result file, and
 * the step "Read the container result" prints it whatever happened: the deployed commit, or the refusal's public code. A failed deploy
 * command keeps the shared code of a failed step.
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
        $payload = ['commit' => null, 'failed_step' => null];
        $status = ValidatedData::nullableString($data['status'] ?? null);
        if ($status === 'succeeded') {
            $payload['commit'] = is_string($file['commit'] ?? null) ? $file['commit'] : null;
        } elseif ($status === 'failed') {
            $step = $failed[0] ?? 'native_execution';
            $payload['failed_step'] = $step;
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
