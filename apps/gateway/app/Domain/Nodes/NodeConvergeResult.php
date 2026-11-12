<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** The terminal wire contract. No arbitrary native output is public domain state. */
final class NodeConvergeResult
{
    private const array Phases = ['input', 'agent', 'software', 'roles', 'tools', 'network', 'check'];

    /** @return array<string,mixed> */
    public static function parse(mixed $value): array
    {
        if (! is_array($value)) {
            self::invalid();
        }
        $raw = array_intersect_key($value, array_flip(['outcome', 'phases', 'failed_phase', 'changed', 'elapsed_seconds', 'tools', 'error_code', 'cleanup_complete', 'failure']));
        $rules = [
            'failure' => ['sometimes', 'required', 'array:task,role,host,reason,rc'],
            'failure.task' => ['present_with:failure', 'nullable', 'string', 'min:1', 'max:120', 'regex:/\A[\x20-\x7e]+\z/D'],
            'failure.role' => ['present_with:failure', 'nullable', 'string', 'min:1', 'max:120', 'regex:/\A[\x20-\x7e]+\z/D'],
            'failure.host' => ['required_with:failure', Rule::in(['target', 'hub'])],
            'failure.reason' => ['required_with:failure', Rule::in(['hidden', 'unreachable', 'timeout', 'assertion', 'command_failed', 'module_failed'])],
            'failure.rc' => ['sometimes', 'integer', 'min:-2147483648', 'max:2147483647', Rule::notIn([0, 124])],
            'outcome' => ['required', Rule::in(['succeeded', 'failed'])],
            'phases' => ['required', 'array', 'list', 'size:7'],
            'phases.*' => ['required', 'array:name,status,changed,elapsed_seconds'],
            'phases.*.status' => ['required', Rule::in(['succeeded', 'failed', 'skipped'])],
            'phases.*.changed' => ['required', 'integer', 'min:0'],
            'phases.*.elapsed_seconds' => ['required', 'numeric', 'min:0', 'max:86400'],
            'changed' => ['required', 'integer', 'min:0'],
            'elapsed_seconds' => ['required', 'numeric', 'min:0', 'max:86400'],
            'cleanup_complete' => ['required', 'boolean'],
            'failed_phase' => ['sometimes', Rule::in(self::Phases)],
            'error_code' => ['sometimes', Rule::in(['operation.native_failed'])],
            'tools' => ['present', 'array', 'list', 'max:10000'],
            'tools.*' => ['required', 'array:id,status,installed_version'],
            'tools.*.id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'tools.*.status' => ['required', Rule::in(['installed', 'failed'])],
            'tools.*.installed_version' => ['present', 'nullable', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
        ];
        foreach (self::Phases as $index => $phase) {
            $rules["phases.{$index}.name"] = ['required', Rule::in([$phase])];
        }
        if (Validator::make($raw, $rules)->fails()) {
            self::invalid();
        }
        $rows = array_map(ValidatedData::object(...), is_array($raw['phases'] ?? null) ? $raw['phases'] : []);
        $elapsed = $raw['elapsed_seconds'];
        if ((! is_int($elapsed) && ! is_float($elapsed)) || ! is_finite((float) $elapsed)
            || ! is_int($raw['changed']) || ! is_bool($raw['cleanup_complete'])) {
            self::invalid();
        }
        $failure = null;
        foreach ($rows as $row) {
            $phaseElapsed = $row['elapsed_seconds'];
            if (! is_int($row['changed']) || (! is_int($phaseElapsed) && ! is_float($phaseElapsed)) || ! is_finite((float) $phaseElapsed)
                || ($row['status'] === 'skipped' && ($row['changed'] !== 0 || $phaseElapsed != 0))) {
                self::invalid();
            }
            if ($row['status'] !== ($failure === null ? ($row['status'] === 'failed' ? 'failed' : 'succeeded') : 'skipped')) {
                self::invalid();
            }
            if ($row['status'] === 'failed') {
                $failure = $row['name'];
            }
        }
        if (($raw['failed_phase'] ?? null) !== $failure || ($raw['outcome'] === 'failed') !== ($failure !== null)
            || ($failure === null && (isset($raw['error_code']) || $raw['cleanup_complete'] !== true))
            || ($failure !== null && ! isset($raw['error_code']))
            || $raw['changed'] !== array_sum(array_column($rows, 'changed'))
            || abs((float) $elapsed - array_sum(array_column($rows, 'elapsed_seconds'))) > 0.001) {
            self::invalid();
        }
        if (array_key_exists('failure', $raw)) {
            $taskFailure = ValidatedData::object($raw['failure']);
            $hasRc = array_key_exists('rc', $taskFailure);
            if ($failure === null || ($taskFailure['reason'] === 'command_failed') !== $hasRc
                || ($hasRc && ! is_int($taskFailure['rc']))
                || ($taskFailure['reason'] === 'hidden' && ($taskFailure['task'] !== null || $taskFailure['role'] !== null))) {
                self::invalid();
            }
        }
        foreach (is_array($raw['tools'] ?? null) ? $raw['tools'] : [] as $tool) {
            $observation = ValidatedData::object($tool);
            if (! is_int($observation['id']) || $rows[4]['status'] === 'skipped'
                || ($observation['status'] === 'installed' && ($observation['installed_version'] === null || $observation['installed_version'] === ''))) {
                self::invalid();
            }
        }

        return ValidatedData::object($raw);
    }

    private static function invalid(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'The Node converge terminal result is invalid.', 422);
    }
}
