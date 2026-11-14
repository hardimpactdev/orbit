<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Logs\LogRedactor;
use App\Infrastructure\Swamp\NativeStepFailure;
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
     * @param  list<string>  $secrets  Values the recorded failure detail must not show: the Instance's environment and the Swamp tokens.
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function from(array $data, array $secrets = []): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $steps = ValidatedData::object($result['steps'] ?? []);
        $failed = ValidatedData::stringList(ValidatedData::object($result['summary'] ?? [])['failed_steps'] ?? []);
        $stdout = ValidatedData::nullableString(ValidatedData::object($steps[self::ResultStep] ?? [])['stdout'] ?? null);
        $file = is_string($stdout) ? json_decode(trim($stdout), true) : null;
        $file = is_array($file) ? $file : [];
        // A nested-fleet start's word for what it did with the Club key, kept in the result file by every later phase.
        $payload = ['commit' => null, 'failed_step' => null, 'started' => true, 'club_key' => $file['club_key'] ?? null];
        // What a nested-fleet refresh did, part by part, kept in the result file by every later phase.
        $refresh = self::fleetRefresh($file['fleet_refresh'] ?? null);
        if ($refresh !== null) {
            $payload['fleet_refresh'] = $refresh;
        }
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
            // A failed nested-fleet phase writes why into the result file: a swamp step that exits nonzero keeps no output, so this is
            // its only record. After a restore, `restore_failure` says the restored fleet did not start too. A failed wait in `start` or
            // `processes` names the units that did not become active.
            $detail = is_string($file['detail'] ?? null) && $file['detail'] !== '' ? $file['detail'] : null;
            if (is_string($file['restore_failure'] ?? null) && $file['restore_failure'] !== '') {
                $native = ValidatedData::object($data['failure'] ?? [])['detail'] ?? null;
                $detail = trim(($detail ?? (is_string($native) ? $native : '')).' '.$file['restore_failure']);
            }
            if ($detail !== null) {
                $native = ValidatedData::object($data['failure'] ?? []);
                $data['failure'] = ['failed_step' => is_string($native['failed_step'] ?? null) ? $native['failed_step'] : $step,
                    'detail' => mb_strcut(app(LogRedactor::class)->redact($detail, $secrets), 0, NativeStepFailure::MaximumBytes, 'UTF-8'),
                    'stderr_recorded' => true];
            }
            // The start after a passed refresh failed: the new seed and `base` stay, so the seed's commit is recorded.
            if ($code === 'instance.container_fleet_resume_failed' && is_string($file['commit'] ?? null)) {
                $payload['commit'] = $file['commit'];
                $payload['seed_kept'] = true;
            }
            // A unit the run saw not become active: its Process is failed; the other active ones keep running.
            if (is_array($file['units_inactive'] ?? null)) {
                $payload['units_inactive'] = array_values(array_filter($file['units_inactive'], is_string(...)));
            }
            // A refused nested-fleet check names each difference, as `<container> <kind> <name>`.
            if ($code === 'instance.container_fleet_drift' && is_array($file['fleet_drift'] ?? null)) {
                $payload['fleet_drift'] = array_slice(array_map(static fn (mixed $line): string => mb_strcut(ValidatedData::string($line), 0, 255, 'UTF-8'),
                    array_values($file['fleet_drift'])), 0, 32);
            }
        }

        return [$data, $payload];
    }

    /**
     * The refresh's own words for each part (docs/reference/development-containers.md#what-a-refresh-skips), or null when the run
     * has no refresh or its words are not these.
     *
     * @return array{migrate:string,restart:string,converge:string,converge_reason:string,changed:int,base:string,base_containers:list<string>,record:string,seconds:array<string,int>}|null
     */
    private static function fleetRefresh(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $words = ['migrate' => ['applied', 'nothing', 'skipped'], 'restart' => ['yes', 'no'], 'converge' => ['ran', 'skipped'],
            'converge_reason' => ['changed', 'record', 'digest_unreadable', 'stale', 'unchanged'], 'base' => ['retaken', 'kept'],
            'record' => ['present', 'missing', 'unreadable']];
        $refresh = [];
        foreach ($words as $part => $allowed) {
            if (! in_array($raw[$part] ?? null, $allowed, true)) {
                return null;
            }
            $refresh[$part] = $raw[$part];
        }
        if (! is_int($raw['changed'] ?? null) || $raw['changed'] < 0) {
            return null;
        }
        $containers = $raw['base_containers'] ?? [];
        if (! is_array($containers) || ! array_is_list($containers) || count($containers) > 3
            || array_filter($containers, static fn (mixed $name): bool => ! is_string($name) || preg_match('/\A[a-z0-9-]{1,63}\z/', $name) !== 1) !== []) {
            return null;
        }
        $refresh = ['migrate' => $refresh['migrate'], 'restart' => $refresh['restart'], 'converge' => $refresh['converge'],
            'converge_reason' => $refresh['converge_reason'], 'changed' => $raw['changed'], 'base' => $refresh['base'], 'base_containers' => $containers, 'record' => $refresh['record']];
        $seconds = is_array($raw['seconds'] ?? null) ? $raw['seconds'] : [];
        $refresh['seconds'] = [];
        foreach (['gateway', 'converge', 'ready', 'base'] as $part) {
            if (is_int($seconds[$part] ?? null) && $seconds[$part] >= 0) {
                $refresh['seconds'][$part] = $seconds[$part];
            }
        }

        return $refresh;
    }
}
