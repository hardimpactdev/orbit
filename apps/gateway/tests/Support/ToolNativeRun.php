<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Swamp\NativeRunResult;

/** Public observations at the native history boundary; no package or transport effects. */
final class ToolNativeRun
{
    /** @param array<string,mixed> $fields
     * @return list<array<string,mixed>>
     */
    public static function steps(array $fields): array
    {
        $error = $fields['error_code'] ?? null;
        $decision = array_intersect_key($fields, array_flip(['manager_version', 'installed_version', 'native_error_code', 'error_code', 'outcome']));
        $decision['ready'] = $error === null;
        $steps = [
            ['name' => 'decide', 'status' => 'succeeded', 'outputs' => $decision],
            ['name' => 'Decode package state', 'status' => $error === null ? 'succeeded' : 'skipped', 'outputs' => array_intersect_key($fields, ['installed_version' => true])],
            ['name' => 'Assert package state', 'status' => $error === null ? 'succeeded' : 'failed'],
        ];
        if ($error === 'tool.manager_provision_failed') {
            $steps[] = ['name' => 'Set up the Tool Manager', 'status' => 'failed'];
        }
        if (array_key_exists('repository_removed', $fields)) {
            $steps[] = ['name' => 'Remove the apt source', 'status' => $fields['repository_removed'] ? 'succeeded' : 'failed', 'allowedFailure' => true];
        }

        return $steps;
    }

    /** @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function result(array $fields): array
    {
        $steps = self::steps($fields);

        return (new NativeRunResult)->fromRun(['status' => isset($fields['error_code']) ? 'failed' : 'succeeded', 'jobs' => [['steps' => $steps]]])
            + ['decision' => $steps[0]['outputs'], 'observation' => $steps[1]['outputs']];
    }
}
