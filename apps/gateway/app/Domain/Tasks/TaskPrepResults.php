<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Support\ValidatedData;
use Closure;

/**
 * Reads the public results of the children of a prep run from what Swamp's run data keeps of their steps. A step that runs a
 * child workflow keeps that child's step outputs keyed by the child's step names, and a shell step's output is a JSON text, so
 * each reader looks for the one value it needs instead of fixing a path through the outputs.
 */
final class TaskPrepResults
{
    /** @return array<string,mixed>|null the provision receipt of a VM that is ready */
    public static function receipt(mixed $captured): ?array
    {
        return self::find($captured, static fn (array $value): bool => isset($value['task_vm_id'], $value['enrollment']) && ($value['ready'] ?? null) === true);
    }

    /** @return array<string,mixed>|null the public result of one finished turn */
    public static function turn(mixed $captured): ?array
    {
        $holder = self::find($captured, static fn (array $value): bool => is_string($value['result_json'] ?? null));
        $result = $holder === null ? null : json_decode(ValidatedData::string($holder['result_json']), true);

        return is_array($result) ? ValidatedData::object($result) : null;
    }

    /**
     * The step that ended a failed run: the one the failure names, else the first failed step of the summary.
     *
     * @param  array<string,mixed>  $native
     * @param  array<string,mixed>  $data
     */
    public static function failedStep(array $native, array $data): ?string
    {
        $failure = ValidatedData::object($data['failure'] ?? []);
        $steps = ValidatedData::object($native['summary'] ?? [])['failed_steps'] ?? [];
        $step = $failure['failed_step'] ?? (is_array($steps) ? ($steps[0] ?? null) : null);

        return is_string($step) ? $step : null;
    }

    /**
     * The public code of a failed publish step.
     *
     * @param  array<string,mixed>  $data
     */
    public static function publishCode(array $data): string
    {
        $failure = is_array($data['failure'] ?? null) ? $data['failure'] : [];
        $detail = $failure['detail'] ?? null;

        return in_array($detail, ['tasks.head_moved', 'tasks.not_fast_forward', 'tasks.workflows_permission', 'tasks.push_failed', 'tasks.publish_failed', 'tasks.repository_execution_unknown'], true)
            ? $detail : 'tasks.publish_failed';
    }

    /** @return array{pull_request_number:int,pull_request_url:string}|null */
    public static function pullRequest(mixed $captured): ?array
    {
        $found = self::find($captured, static fn (array $value): bool => is_string($value['pull_request_url'] ?? null) && is_int($value['pull_request_number'] ?? null));

        return $found === null ? null : ['pull_request_number' => ValidatedData::integer($found['pull_request_number']), 'pull_request_url' => ValidatedData::string($found['pull_request_url'])];
    }

    /**
     * @param  Closure(array<array-key,mixed>): bool  $accept
     * @return array<array-key,mixed>|null
     */
    private static function find(mixed $value, Closure $accept): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode(trim($value), true);

            return is_array($decoded) ? self::find($decoded, $accept) : null;
        }
        if (! is_array($value)) {
            return null;
        }
        if (! array_is_list($value) && $accept($value)) {
            return $value;
        }
        foreach ($value as $item) {
            if (($found = self::find($item, $accept)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
