<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Support\ValidatedData;

/**
 * A development deployment's outcome from Swamp's native run data: the first failed step names the error code, the
 * checked-out commit comes from the end check's read, and the Vite+ runtime from its one capture.
 */
final readonly class DevelopmentDeployOutcome
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function from(array $data): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $steps = ValidatedData::object($result['steps'] ?? []);
        $failed = ValidatedData::stringList(ValidatedData::object($result['summary'] ?? [])['failed_steps'] ?? []);
        $payload = ['commit' => null, 'failed_step' => null];
        $status = ValidatedData::nullableString($data['status'] ?? null);
        if ($status === 'succeeded') {
            $payload['commit'] = trim(ValidatedData::nullableString(ValidatedData::object($steps['Read the checkout commit'] ?? [])['stdout'] ?? null) ?? '');
        } elseif ($status === 'failed') {
            $step = $failed[0] ?? 'native_execution';
            $payload['failed_step'] = $step;
            $data = [...$data, 'error_code' => self::code($step)];
        }
        $vp = self::vpEnvironment($result);
        if ($vp !== null) {
            $payload['vp_environment'] = $vp;
        }

        return [$data, $payload];
    }

    /**
     * The Vite+ runtime the deploy steps ran under, from the shared Vite+ steps a development and a production deploy
     * both run: only when every one of its installs was verified and no step failed.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    public static function vpEnvironment(array $result): ?array
    {
        $steps = ValidatedData::object($result['steps'] ?? []);
        $failed = ValidatedData::stringList(ValidatedData::object($result['summary'] ?? [])['failed_steps'] ?? []);
        $verified = array_filter($steps, static fn (string $name): bool => str_starts_with($name, 'dev-vp-verify'), ARRAY_FILTER_USE_KEY);

        return is_array($result['vp_runtime'] ?? null) && $verified !== [] && $failed === []
            ? [...ValidatedData::object($result['vp_runtime']), 'status' => 'active'] : null;
    }

    /** A failed SSH step keeps no exit code, so each refusal has its own step. */
    private static function code(string $step): string
    {
        return match (true) {
            $step === 'Check the checkout lock' => 'instance.lifecycle_busy',
            $step === 'Check tracked changes' => 'deployment.checkout_dirty',
            in_array($step, ['Count commits after it', 'Read the branch ancestor', 'Check it is on the branch'], true) => 'deployment.commit_not_on_branch',
            $step === 'Check the local branch' => 'deployment.branch_diverged',
            in_array($step, ['Write the fetch credential', 'Fetch the branch', 'Resolve the commit', 'Check out the commit'], true)
                || str_starts_with($step, 'Clean stale directories') => 'deployment.source_failed',
            $step === 'dev-vp-requirements' => 'deployment.vp_requirements_invalid',
            str_starts_with($step, 'dev-vp-') => 'deployment.vp_store_failed',
            str_starts_with($step, 'Run the deploy steps') => 'deployment.step_failed',
            in_array($step, ['Read the checkout branch', 'Read the checkout commit', 'Check the checkout'], true) => 'deployment.branch_changed',
            default => 'operation.native_failed',
        };
    }
}
