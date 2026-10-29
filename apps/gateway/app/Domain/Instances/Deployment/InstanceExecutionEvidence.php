<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * Typed reads of family evidence; these helpers never record or manufacture a receipt.
 *
 * @phpstan-type Run array{name:?string,transport:?string,step:?string,started:bool,result:?array<string,mixed>}
 */
final class InstanceExecutionEvidence
{
    /** @return Run|null */
    public static function run(Operation $operation, string $key): ?array
    {
        $runs = $operation->state['executor_runs'] ?? [];
        $run = is_array($runs) ? ($runs[$key] ?? null) : null;
        if (! is_array($run)) {
            return null;
        }

        return ['name' => ValidatedData::nullableString($run['name'] ?? null),
            'transport' => ValidatedData::nullableString($run['transport'] ?? null),
            'step' => ValidatedData::nullableString($run['step'] ?? null), 'started' => ($run['started'] ?? false) === true,
            'result' => is_array($run['result'] ?? null) ? ValidatedData::object($run['result']) : null];
    }

    /** @return array<string,mixed>|null */
    public static function result(Operation $operation, string $key): ?array
    {
        return self::run($operation, $key)['result'] ?? null;
    }

    /** @return array<string,mixed>|null */
    public static function step(Operation $operation, string $key): ?array
    {
        $steps = $operation->state['steps'] ?? [];
        $result = is_array($steps) ? ($steps[$key] ?? null) : null;

        return is_array($result) ? ValidatedData::object($result) : null;
    }
}
