<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * A production deployment's outcome from Swamp's native run data (docs/cli/instance/deploy.mmd): the first failed step
 * names the error code, `current` moved only when the release was activated and swapped in, and the Process
 * definitions report the steps that installed or restarted them.
 */
final readonly class ProductionDeployOutcome
{
    /** Each chart step, by name prefix, and the error code its failure records. */
    private const array Codes = [
        'Write the release credential' => 'deployment.source_failed',
        'Prepare the release' => 'deployment.source_failed',
        'Remove the release credential' => 'deployment.source_failed',
        'Link the release' => 'deployment.source_failed',
        'Read the release commit' => 'deployment.source_failed',
        'Read the first-parent chain' => 'deployment.commit_not_on_branch',
        'Check the commit is on the branch' => 'deployment.commit_not_on_branch',
        'Read the key names' => 'env.sync_unconfirmed',
        'Check for foreign keys' => 'env.sync_would_drop_keys',
        'Write .env' => 'env.sync_unconfirmed',
        'dev-vp-requirements' => 'deployment.vp_requirements_invalid',
        'dev-vp-' => 'deployment.vp_store_failed',
        'Write the deploy steps' => 'deployment.step_failed',
        'Run before steps' => 'deployment.step_failed',
        'Grant Caddy access' => 'deployment.selection_invalid',
        'Activate release' => 'deployment.selection_invalid',
        'Swap current' => 'deployment.selection_invalid',
        'Refresh PHP cache' => 'deployment.php_runtime_unconfirmed',
        'Install Process definitions' => 'process.install_failed',
        'Restart Processes' => 'process.restart_failed',
        'Run after steps' => 'deployment.step_failed',
        'Remove the deploy steps' => 'deployment.step_failed',
        'Read current back' => 'deployment.selection_invalid',
        'Check the deployment' => 'deployment.selection_invalid',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function from(array $data, Operation $operation): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $steps = ValidatedData::object($result['steps'] ?? []);
        $status = static fn (string $name): ?string => NativeRunResult::stepStatus($result, $name);
        $release = InstanceDeploymentIntent::releaseName($operation);
        $instance = Instance::query()->findOrFail($operation->target_id);
        $activated = $status('Activate release') === 'succeeded' && $status('Swap current') === 'succeeded';
        $read = trim(ValidatedData::nullableString(ValidatedData::object($steps['Read the release commit'] ?? [])['stdout'] ?? null) ?? '');
        $payload = ['release' => ['name' => $release], 'commit' => $read !== '' ? $read : InstanceDeploymentIntent::commit($operation),
            'selected_release' => ['name' => $activated ? $release : RecordedReleases::afterDeploy($instance, $release)['previous']],
            'selection_confirmed' => $activated, 'failed_step' => null,
            'warnings' => count(array_filter($steps, static fn (mixed $step, string $name): bool => (str_starts_with($name, 'Find releases in use') || str_starts_with($name, 'Clean up releases'))
                && is_array($step) && ($step['status'] ?? null) === 'failed', ARRAY_FILTER_USE_BOTH))];
        if (ValidatedData::nullableString($data['status'] ?? null) === 'failed') {
            $failed = ValidatedData::stringList(ValidatedData::object($result['summary'] ?? [])['failed_steps'] ?? []);
            [$payload['failed_step'], $code] = self::failure($operation, $failed[0] ?? null);
            $data = [...$data, 'error_code' => $code];
        }
        if ($status('Write .env-0') === 'succeeded') {
            $payload['environment'] = ['keys' => ValidatedData::stringList(ValidatedData::object($operation->input['direct_environment'])['keys'])];
        }
        $payload['runtime_definitions'] = self::members($operation, $result);
        $vp = DevelopmentDeployOutcome::vpEnvironment($result);
        if ($vp !== null) {
            $payload['vp_environment'] = $vp;
        }

        return [$data, $payload];
    }

    /** @return array{0: string, 1: string} the failed step, a deploy step by its phase and name, and its error code */
    private static function failure(Operation $operation, ?string $step): array
    {
        if ($step === null) {
            return ['native_execution', 'operation.native_failed'];
        }
        foreach (self::Codes as $label => $code) {
            if (! str_starts_with($step, $label)) {
                continue;
            }
            $phase = match ($label) {
                'Run before steps' => 'before_activation',
                'Run after steps' => 'after_activation',
                default => null,
            };
            if ($phase !== null && preg_match('/-(\d+)\z/', $step, $index) === 1) {
                $steps = array_values(array_filter(InstanceDeploymentIntent::admitted($operation)['steps'], static fn (array $entry): bool => $entry['phase'] === $phase));

                return [$phase.'.'.($steps[(int) $index[1]]['name'] ?? $step), $code];
            }

            return [$label, $code];
        }

        return [$step, 'operation.native_failed'];
    }

    /**
     * The definitions each install or restart step reported; a member whose step never ran is not reported.
     *
     * @param  array<string, mixed>  $result
     * @return array{processes: list<array<string,mixed>>, schedules: list<array<string,mixed>>, restart: list<array<string,mixed>>}
     */
    private static function members(Operation $operation, array $result): array
    {
        $captured = ValidatedData::object($operation->input['runtime_definitions']);
        $outcomes = ['processes' => [], 'schedules' => [], 'restart' => []];
        foreach (['processes' => ['Install Process definitions', 'process_id', 'process.install_failed'],
            'restart' => ['Restart Processes', 'process_id', 'process.restart_failed']] as $family => [$label, $key, $code]) {
            $entries = $captured[$family];
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new \InvalidArgumentException('Captured deployment definitions must be lists.');
            }
            foreach ($entries as $index => $entry) {
                $status = NativeRunResult::stepStatus($result, "{$label}-{$index}");
                if ($status !== 'succeeded' && $status !== 'failed') {
                    continue;
                }
                $id = ValidatedData::object($entry)[$key];
                $outcomes[$family][] = [$key => $id, 'ready' => $status === 'succeeded', ...$status === 'failed' ? ['error_code' => $code] : []];
            }
        }

        return $outcomes;
    }
}
