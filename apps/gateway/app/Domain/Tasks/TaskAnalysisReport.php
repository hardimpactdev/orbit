<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Support\StrictJson;
use InvalidArgumentException;

/** Gateway validates transport and identity only. Payload semantics remain repository-owned. */
final class TaskAnalysisReport
{
    public const int MaxBytes = 65536;

    /** @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    public static function validate(string $bytes, array $identity): array
    {
        if (strlen($bytes) > self::MaxBytes) {
            throw new ResourceOperationException('tasks.analysis_report_too_large', 'The complete analysis envelope exceeds 64 KiB.', 422);
        }
        try {
            $object = StrictJson::decode($bytes, self::MaxBytes);
            $value = StrictJson::fields($object, ['schema', 'execution_id', 'plan_sha256', 'base', 'head', 'tree', 'status', 'diagnostics', 'report']);
            if ($value['schema'] !== 1 || ! in_array($value['status'], ['ready', 'action_required', 'blocked'], true)) {
                throw new InvalidArgumentException('Invalid analysis envelope version or status.');
            }
            foreach (['execution_id', 'plan_sha256', 'base', 'head', 'tree'] as $key) {
                if (! is_string($value[$key]) || $value[$key] !== ($identity[$key] ?? null)) {
                    throw new InvalidArgumentException('Analysis envelope identity differs.');
                }
            }
            if (! is_array($value['diagnostics'])) {
                throw new InvalidArgumentException('Analysis diagnostics must be a list.');
            }
            foreach ($value['diagnostics'] as $diagnostic) {
                $fields = StrictJson::fields($diagnostic, ['code', 'message']);
                if (! is_string($fields['code']) || trim($fields['code']) === '' || ! is_string($fields['message'])) {
                    throw new InvalidArgumentException('Invalid analysis diagnostic.');
                }
            }
            $report = StrictJson::fields($value['report'], ['schema', 'data']);
            if (! is_string($report['schema']) || trim($report['schema']) === '' || ! $report['data'] instanceof \stdClass) {
                throw new InvalidArgumentException('Invalid repository report.');
            }
            self::finite($value);
        } catch (InvalidArgumentException $exception) {
            throw new ResourceOperationException('tasks.analysis_report_invalid', $exception->getMessage(), 422, $exception);
        }

        return StrictJson::associative($object);
    }

    /** A nonzero adapter exit may carry a bounded failure, never a successful artifact. */
    public static function rejectFailure(string $bytes): void
    {
        try {
            $object = StrictJson::decode($bytes, self::MaxBytes);
            if (! $object instanceof \stdClass || ! property_exists($object, 'error')) {
                return;
            }
            $value = StrictJson::fields($object, ['schema', 'error']);
            $error = StrictJson::fields($value['error'], ['code', 'required_bytes']);
            if ($value['schema'] !== 1) {
                throw new InvalidArgumentException('Invalid analysis failure version.');
            }
            // An adapter overflow must know its exact size. Only Gateway-side overflow may lack it.
            if ($error['code'] === 'tasks.analysis_report_too_large' && $error['required_bytes'] === null) {
                throw new InvalidArgumentException('Invalid analysis failure code or required size.');
            }
            throw TaskAnalysisFailure::fromResult(StrictJson::associative($object));
        } catch (InvalidArgumentException $exception) {
            throw new ResourceOperationException('tasks.analysis_report_invalid', $exception->getMessage(), 422, $exception);
        }
    }

    private static function finite(mixed $value): void
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Non-finite analysis number.');
        }
        if (is_array($value) || $value instanceof \stdClass) {
            foreach (is_array($value) ? $value : get_object_vars($value) as $item) {
                self::finite($item);
            }
        }
    }
}
