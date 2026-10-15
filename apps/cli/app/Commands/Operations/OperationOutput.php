<?php

declare(strict_types=1);

namespace App\Commands\Operations;

use Orbit\Sdk\Responses\Operations\OperationResponse;

/** The human fields of an operation, shared by `operation:show` and `operation:cancel`. */
final class OperationOutput
{
    /** @return array<string, string|int|null> */
    public static function detail(OperationResponse $operation): array
    {
        $result = $operation->result === null ? null : (string) json_encode($operation->result, JSON_UNESCAPED_SLASHES);

        return [
            'Kind' => $operation->kind,
            'Target' => "{$operation->targetType} {$operation->targetId}",
            'Status' => $operation->status,
            'Current step' => $operation->currentStep,
            'Failed step' => $operation->failedStep,
            'Error code' => $operation->errorCode,
            'Result' => $result,
            'Cancel requested' => $operation->cancelRequested ? 'yes' : null,
            'Attempts' => $operation->attempts,
            'By' => $operation->triggeredBy,
            'Created' => $operation->createdAt,
            'Started' => $operation->startedAt,
            'Finished' => $operation->finishedAt,
        ];
    }

    /** @return list<string> */
    public static function row(OperationResponse $operation): array
    {
        return [
            (string) $operation->id,
            $operation->kind,
            "{$operation->targetType} {$operation->targetId}",
            $operation->status,
            $operation->currentStep ?? $operation->failedStep ?? '',
            $operation->triggeredBy ?? '',
            $operation->startedAt ?? $operation->createdAt ?? '',
        ];
    }
}
