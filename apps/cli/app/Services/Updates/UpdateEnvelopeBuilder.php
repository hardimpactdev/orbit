<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Orbit\Core\Http\JsonEnvelope;

final class UpdateEnvelopeBuilder
{
    /**
     * Build the canonical success envelope for a completed or skipped local
     * update. Completed updates carry the version transition and doctor issue
     * count; skips carry only the resolved versions and the skip status.
     *
     * @return array<string, mixed>
     */
    public static function success(LocalUpdateResult $result): array
    {
        $update = [
            'scope' => 'local',
            'target' => 'local',
            'status' => $result->status,
            'from_version' => $result->fromVersion,
            'to_version' => $result->toVersion,
            'latest_version' => $result->latestVersion,
            'doctor_issues' => $result->doctorIssues,
            'steps' => self::steps($result->stepResults),
        ];

        return JsonEnvelope::success(['update' => $update]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function failure(string $code, string $message, array $data = [], array $meta = []): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return ['error' => $error];
    }

    /**
     * @param  array<string, string>  $stepResults
     * @return list<array{name: string, status: string}>
     */
    private static function steps(array $stepResults): array
    {
        $steps = [];

        foreach ($stepResults as $name => $status) {
            $steps[] = ['name' => $name, 'status' => $status];
        }

        return $steps;
    }
}
