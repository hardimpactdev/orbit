<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Logs\LogRedactor;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * A deployment in a default Instance's container, from Swamp's native run data. Each Ansible phase writes the run's result file, and
 * the step "Read the container result" prints it whatever happened: the deployed commit, or the refusal's public code. A failed deploy
 * command keeps the shared code of a failed step. `started` says whether the container ran by the end.
 */
final readonly class ContainerDeployOutcome
{
    public const string ResultStep = 'Read the container result';

    /** The step whose first phase starts the container (docs/cli/instance/deploy.mmd). */
    public const string PrepareStep = 'Prepare the container';

    /**
     * The proof default's step that waits for its seed hold before any phase (docs/reference/development-containers.md#the-seed-hold); at
     * its bound the deployment fails before it changes the container.
     */
    public const string SeedHoldStep = 'Hold the seed';

    /** The forEach step that runs each development deploy step, one item per admitted step. */
    public const string DeployStepsStep = 'Run dev deploy steps';

    /** The step that prints, as `,<name>,<name>,`, the steps whose inputs the checkout found unchanged. */
    public const string SkipStep = 'Read the steps to skip';

    /** The input's `origin` of a deployment that the release's nested-fleet refresh admitted. */
    public const string ReleaseOrigin = 'release';

    /** The input's `origin` of a deployment that the automatic policy admitted. */
    public const string AutomaticOrigin = 'automatic';

    /** Whether a deployment keeps a checkout that is already past its commit: one that a release or the automatic policy admitted. */
    public static function keepsNewer(Operation $operation): bool
    {
        return in_array(ValidatedData::object($operation->input ?? [])['origin'] ?? null, [self::ReleaseOrigin, self::AutomaticOrigin], true);
    }

    /** Whether the release's nested-fleet refresh admitted this deployment, by its admission's own word. */
    public static function releaseAdmitted(Operation $operation): bool
    {
        return $operation->kind === 'instance.deploy' && (ValidatedData::object($operation->input ?? [])['origin'] ?? null) === self::ReleaseOrigin;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $secrets  Values the recorded failure detail must not show: the Instance's environment and the Swamp tokens.
     * @param  array<mixed>  $admittedSteps  The deployment's development deploy steps as the admission froze them.
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function from(array $data, array $secrets = [], array $admittedSteps = []): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $steps = ValidatedData::object($result['steps'] ?? []);
        $failed = ValidatedData::stringList(ValidatedData::object($result['summary'] ?? [])['failed_steps'] ?? []);
        $stdout = ValidatedData::nullableString(ValidatedData::object($steps[self::ResultStep] ?? [])['stdout'] ?? null);
        $file = is_string($stdout) ? json_decode(trim($stdout), true) : null;
        $file = is_array($file) ? $file : [];
        // A nested-fleet start's word for what it did with the Club key, kept in the result file by every later phase.
        $payload = ['commit' => null, 'failed_step' => null, 'started' => true, 'club_key' => $file['club_key'] ?? null];
        if (is_array($file['executor'] ?? null)) {
            $payload['executor'] = $file['executor'];
            $payload['wake'] = ($file['wake'] ?? null) === 'started' ? 'started' : null;
        }
        // What the checkout removed from a default's tree, names only (docs/reference/development-containers.md#the-default-keeps-a-clean-tree).
        $cleaned = self::workspaceCleaned($file['workspace_cleaned'] ?? null);
        if ($cleaned !== null) {
            $payload['workspace_cleaned'] = $cleaned;
        }
        $bestEffort = self::bestEffortSteps($steps, $admittedSteps);
        if ($bestEffort !== []) {
            $payload['best_effort_steps'] = $bestEffort;
        }
        // What a nested-fleet refresh did, part by part, kept in the result file by every later phase.
        $refresh = self::fleetRefresh($file['fleet_refresh'] ?? null);
        if ($refresh !== null) {
            $payload['fleet_refresh'] = $refresh;
        }
        $status = ValidatedData::nullableString($data['status'] ?? null);
        if ($status === 'succeeded') {
            $payload['commit'] = is_string($file['commit'] ?? null) ? $file['commit'] : null;
            // The checkout was already past the commit: the run kept it as it was and changed nothing, so `commit` is the checkout's.
            if (($file['already_newer'] ?? null) === true) {
                $payload['already_newer'] = true;
            }
        } elseif ($status === 'failed') {
            $step = $failed[0] ?? 'native_execution';
            $payload['failed_step'] = $step;
            // A deployment that failed before the container started leaves its power state as it was. "Prepare the container" starts
            // it in its first phase, `start`; each phase that passed writes itself into `phases_passed` as it ends, also when a later
            // phase is killed before it can write a failure.
            $passed = is_array($file['phases_passed'] ?? null) ? $file['phases_passed'] : [];
            $payload['started'] = ! in_array($step, ['native_execution', 'Write the container intent', self::SeedHoldStep], true)
                && ($step !== self::PrepareStep || in_array('start', $passed, true));
            $code = match (true) {
                str_starts_with($step, self::DeployStepsStep) => 'deployment.step_failed',
                $step === self::SeedHoldStep => 'instance.seed_hold_timeout',
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
     * How each admitted development deploy step ended, in the admitted order (docs/reference/deployments.md#history). The steps run one
     * after another, and the first that fails stops the rest:
     *
     * - `succeeded`, or `failed` when its command failed. A best-effort step never fails the deployment, so its command's exit code
     *   decides: 0 is `succeeded`, 124 `timed_out` and any other code `failed`.
     * - `skipped_unchanged`: the run skipped it because the checkout found its inputs unchanged ("Read the steps to skip" names it).
     * - `interrupted`: it started, and the run ended before it did.
     * - `not_run`: an earlier step stopped the sequence, or the run never reached it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<mixed>  $admitted
     * @return list<array{name:string,phase:DeploymentProgressPhase,status:string}>
     */
    public static function stepEvents(array $data, array $admitted): array
    {
        $steps = ValidatedData::object(ValidatedData::object($data['result'] ?? [])['steps'] ?? []);
        $skip = ValidatedData::object($steps[self::SkipStep] ?? []);
        $unchanged = ($skip['status'] ?? null) === 'succeeded' && is_string($skip['stdout'] ?? null) ? $skip['stdout'] : '';
        $items = self::items($steps);
        $events = [];
        $stopped = false;
        foreach (array_values($admitted) as $index => $intent) {
            if (! is_array($intent) || ! is_string($intent['name'] ?? null)) {
                continue;
            }
            $item = $items[$index] ?? [];
            $status = match (true) {
                $stopped => 'not_run',
                ($item['status'] ?? null) === 'succeeded' => ($intent['required'] ?? null) !== false ? 'succeeded'
                    : match (self::exitCode($item['stdout'] ?? null)) {
                        0 => 'succeeded',
                        124 => 'timed_out',
                        default => 'failed',
                    },
                ($item['status'] ?? null) === 'failed' => 'failed',
                ($item['status'] ?? null) === 'skipped' && str_contains($unchanged, ','.$intent['name'].',') => 'skipped_unchanged',
                in_array($item['status'] ?? null, ['running', 'interrupted', 'cancelled'], true) => 'interrupted',
                default => 'not_run',
            };
            // A step that did not pass stops the ones after it; a best-effort step's own failure does not.
            $stopped = $stopped || in_array($item['status'] ?? null, ['failed', 'running', 'interrupted', 'cancelled'], true);
            $events[] = ['name' => $intent['name'],
                'phase' => DeploymentProgressPhase::tryFrom(is_string($intent['phase'] ?? null) ? $intent['phase'] : '') ?? DeploymentProgressPhase::BeforeActivation,
                'status' => $status];
        }

        return $events;
    }

    /**
     * Each development deploy step's run data by its admitted index. Swamp names each forEach item `<step>-<index>-<index>`.
     *
     * @param  array<string, mixed>  $steps
     * @return array<int, array<mixed>>
     */
    private static function items(array $steps): array
    {
        $items = [];
        foreach ($steps as $name => $step) {
            if (preg_match('/\A'.preg_quote(self::DeployStepsStep, '/').'-([0-9]+)(?:-\1)?\z/D', (string) $name, $match) === 1) {
                $items[(int) $match[1]] = is_array($step) ? $step : [];
            }
        }
        ksort($items);

        return $items;
    }

    /**
     * The checkout's clean of a default as it printed it, or null for any other shape: `removed_count`, the first 20 `removed` names and
     * `left_count` when a file stayed.
     *
     * @return array{removed_count: int, removed: list<string>, left_count?: int}|null
     */
    private static function workspaceCleaned(mixed $raw): ?array
    {
        if (! is_array($raw) || ! is_int($raw['removed_count'] ?? null) || $raw['removed_count'] < 0 || ! is_array($raw['removed'] ?? null)
            || ! array_is_list($raw['removed']) || count($raw['removed']) > 20 || array_filter($raw['removed'], static fn (mixed $name): bool => ! is_string($name)) !== []
            || (array_key_exists('left_count', $raw) && (! is_int($raw['left_count']) || $raw['left_count'] < 1))) {
            return null;
        }

        return ['removed_count' => $raw['removed_count'], 'removed' => array_map(static fn (string $name): string => mb_strcut($name, 0, 255, 'UTF-8'), $raw['removed']),
            ...(isset($raw['left_count']) ? ['left_count' => $raw['left_count']] : [])];
    }

    /** A best-effort step's one output line, its command's exit code: an integer from 0 to 255, or null for anything else. */
    private static function exitCode(mixed $stdout): ?int
    {
        $line = is_string($stdout) ? trim($stdout) : '';

        return preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $line) === 1 && (int) $line <= 255 ? (int) $line : null;
    }

    /**
     * The exit code of each best-effort (`required: false`) development deploy step that ran, in the admitted order
     * (docs/reference/deployments.md#development-deploy-steps). The step's wrapper sends the command's output to /dev/null and prints only the
     * command's exit code, so that line is all this reads. Anything but an integer from 0 to 255 is null; the text is never kept. A
     * skipped step has no row.
     *
     * @param  array<string, mixed>  $steps
     * @param  array<mixed>  $admitted
     * @return list<array{name:string,exit_code:?int}>
     */
    private static function bestEffortSteps(array $steps, array $admitted): array
    {
        $rows = [];
        foreach (self::items($steps) as $index => $step) {
            $intent = $admitted[$index] ?? null;
            if (! is_array($intent) || ($intent['required'] ?? null) !== false || ! is_string($intent['name'] ?? null)
                || ($step['status'] ?? null) === 'skipped') {
                continue;
            }
            $rows[] = ['name' => $intent['name'], 'exit_code' => self::exitCode($step['stdout'] ?? null)];
        }

        return $rows;
    }

    /**
     * One reason for each of the three nested Nodes: `digest_unreadable` with its `error`, `no_record`, `digest_changed`, `stale`,
     * `host_due` (nested-app-dev only: its base digest is unchanged, but the Instance host part is due) or `unchanged`. `unchanged` means
     * the Node did not converge, and every other reason that it did. A converged Node may name the skippable `phases` its converge ran.
     *
     * @param  list<string>  $converged
     * @return array<string,array{reason:string,error?:string,phases?:list<string>}>|null
     */
    private static function convergeReasons(mixed $raw, array $converged): ?array
    {
        if (! is_array($raw) || array_keys($raw) !== ['gateway', 'app-dev', 'app-prod']) {
            return null;
        }
        $reasons = [];
        foreach ($raw as $node => $entry) {
            $reason = is_array($entry) ? ($entry['reason'] ?? null) : null;
            if (! is_array($entry) || ! in_array($reason, ['digest_unreadable', 'no_record', 'digest_changed', 'stale', 'host_due', 'unchanged'], true)
                || ($reason === 'unchanged') === in_array($node, $converged, true) || ($reason === 'host_due' && $node !== 'app-dev')
                || array_diff(array_keys($entry), ['reason', 'error', 'phases']) !== []
                || ($reason === 'digest_unreadable') !== (is_string($entry['error'] ?? null) && $entry['error'] !== '' && mb_strlen($entry['error']) <= 300)
                || ($reason !== 'digest_unreadable' && array_key_exists('error', $entry))) {
                return null;
            }
            $phases = $entry['phases'] ?? null;
            if (array_key_exists('phases', $entry) && (! in_array($node, $converged, true) || ! is_array($phases) || ! array_is_list($phases)
                || array_diff($phases, ['agent', 'software', 'roles', 'tools', 'network']) !== [])) {
                return null;
            }
            $reasons[$node] = ['reason' => $reason, ...(isset($entry['error']) ? ['error' => $entry['error']] : []),
                ...(is_array($phases) ? ['phases' => array_values(array_map(ValidatedData::string(...), $phases))] : [])];
        }

        return $reasons;
    }

    /**
     * The refresh's own words for each part (docs/reference/development-containers.md#what-a-refresh-skips), or null when the run
     * has no refresh or its words are not these.
     *
     * @return array{migrate:string,restart:string,converge:string,converge_reasons:array<string,array{reason:string,error?:string,phases?:list<string>}>,converge_nodes:list<string>,changed:int,tokens:string,tokens_reason:string,base:string,base_containers:list<string>,record:string,tools:string,tools_reason:string,shrink:string,shrink_reason:string,swamp:string,swamp_reason:string,swamp_version:?string,host?:'converged'|'kept'|'skipped_idmap_small',seconds:array<string,int>}|null
     */
    private static function fleetRefresh(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $words = ['migrate' => ['applied', 'nothing', 'skipped'], 'restart' => ['yes', 'no'], 'converge' => ['ran', 'skipped'],
            'tokens' => ['reminted', 'young', 'skipped'], 'tokens_reason' => ['age', 'incomplete', 'unreadable', 'young', 'recorded', 'no_key'], 'base' => ['retaken', 'kept'],
            'record' => ['present', 'missing', 'unreadable'], 'tools' => ['present', 'built', 'failed'],
            'tools_reason' => ['current', 'missing', 'names', 'package', 'venv', 'collections', 'cleanup', 'timeout', 'unreadable'],
            'shrink' => ['started', 'failed', 'skipped'], 'shrink_reason' => ['record', 'packages', 'shrunk', 'current', 'unreadable'],
            'swamp' => ['installed', 'pinned', 'skipped'], 'swamp_reason' => ['changed', 'unreadable', 'pinned', 'no_key']];
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
        $nodes = $raw['converge_nodes'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes) || array_filter($nodes, static fn (mixed $name): bool => ! in_array($name, ['gateway', 'app-dev', 'app-prod'], true)) !== []
            || count(array_unique($nodes)) !== count($nodes) || ($refresh['converge'] === 'skipped') !== ($nodes === [])) {
            return null;
        }
        // Each nested Node's own reason, and for a converged Node the phases its converge ran
        // (docs/reference/development-containers.md#what-a-refresh-skips).
        $reasons = self::convergeReasons($raw['converge_reasons'] ?? null, $nodes);
        if ($reasons === null) {
            return null;
        }
        // The recipe's Swamp release as the refresh read it; none when it read nothing.
        $swampVersion = $raw['swamp_version'] ?? null;
        if (! array_key_exists('swamp_version', $raw) || ($swampVersion !== null && (! is_string($swampVersion)
            || preg_match('/\A[0-9]{8}\.[0-9]{6}\.[0-9]+-sha\.[0-9a-f]{8}\z/', $swampVersion) !== 1))) {
            return null;
        }
        $containers = $raw['base_containers'] ?? [];
        if (! is_array($containers) || ! array_is_list($containers) || count($containers) > 3
            || array_filter($containers, static fn (mixed $name): bool => ! is_string($name) || preg_match('/\A[a-z0-9-]{1,63}\z/', $name) !== 1) !== []) {
            return null;
        }
        $refresh = ['migrate' => $refresh['migrate'], 'restart' => $refresh['restart'], 'converge' => $refresh['converge'],
            'converge_reasons' => $reasons, 'converge_nodes' => $nodes, 'changed' => $raw['changed'], 'tokens' => $refresh['tokens'], 'tokens_reason' => $refresh['tokens_reason'],
            'base' => $refresh['base'], 'base_containers' => $containers, 'record' => $refresh['record'], 'tools' => $refresh['tools'],
            'tools_reason' => $refresh['tools_reason'], 'shrink' => $refresh['shrink'], 'shrink_reason' => $refresh['shrink_reason'],
            'swamp' => $refresh['swamp'], 'swamp_reason' => $refresh['swamp_reason'], 'swamp_version' => $swampVersion];
        // What it did with the Instance host on nested-app-dev. Unlike the parts above it is optional: a run of an Ansible from before the
        // part (such as after a rollback) names none, and its other parts are still true, so a missing or unknown word only leaves `host`
        // out and drops nothing else.
        if (in_array($raw['host'] ?? null, ['converged', 'kept', 'skipped_idmap_small'], true)) {
            $refresh['host'] = $raw['host'];
        }
        $seconds = is_array($raw['seconds'] ?? null) ? $raw['seconds'] : [];
        $refresh['seconds'] = [];
        foreach (['gateway', 'converge', 'ready', 'base', 'tools'] as $part) {
            if (is_int($seconds[$part] ?? null) && $seconds[$part] >= 0) {
                $refresh['seconds'][$part] = $seconds[$part];
            }
        }

        return $refresh;
    }
}
