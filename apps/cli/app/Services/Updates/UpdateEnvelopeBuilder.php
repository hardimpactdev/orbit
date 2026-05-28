<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Orbit\Core\Http\JsonEnvelope;

final class UpdateEnvelopeBuilder
{
    /**
     * @param  array<string, string>  $stepResults
     * @return array<string, mixed>
     */
    public static function success(array $stepResults): array
    {
        $steps = [];

        foreach ($stepResults as $name => $status) {
            $steps[] = ['name' => $name, 'status' => $status];
        }

        return JsonEnvelope::success([
            'update' => [
                'scope' => 'local',
                'target' => 'local',
                'status' => 'completed',
                'steps' => $steps,
            ],
        ]);
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
}
