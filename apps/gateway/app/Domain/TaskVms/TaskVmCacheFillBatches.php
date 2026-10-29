<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** Immutable fill input partitioning; Swamp executes every batch through a child Operation. */
final class TaskVmCacheFillBatches
{
    public const int MaxItems = 4096;

    public const int MaxBatches = 128;

    /** @param list<array{manager:string,package:string,version:string,url:string,integrity:string|null,key:string}> $artifacts
     * @param  array<string,string>  $conflicts
     * @return list<array{artifacts:list<array{manager:string,package:string,version:string,url:string,integrity:string|null,key:string}>,conflicts:array<string,string>}>
     */
    public static function compile(array $artifacts, array $conflicts): array
    {
        $batches = [];
        $batch = ['artifacts' => [], 'conflicts' => []];
        $bytes = 256;
        foreach ([...array_column($artifacts, null, 'key'), ...$conflicts] as $key => $value) {
            if (strlen($key) > 1024) {
                throw new TaskVmException('task_vm.package_cache_invalid_result', 'The cache key exceeds the Operation result limit.');
            }
            // A failure is at most 160 printable ASCII bytes, each requiring at most two JSON bytes.
            $cost = strlen(json_encode($key, JSON_THROW_ON_ERROR)) + 324;
            if ($bytes + $cost > 196608 || count($batch['artifacts']) + count($batch['conflicts']) === self::MaxItems) {
                $batches[] = $batch;
                $batch = ['artifacts' => [], 'conflicts' => []];
                $bytes = 256;
            }
            if (is_array($value)) {
                $batch['artifacts'][] = $value;
            } else {
                $batch['conflicts'][$key] = $value;
            }
            $bytes += $cost;
        }
        $batches[] = $batch;
        if (count($batches) > self::MaxBatches) {
            throw new TaskVmException('task_vm.package_cache_invalid_limit', 'The cache inventory exceeds the bounded child plan.');
        }

        return $batches;
    }
}
