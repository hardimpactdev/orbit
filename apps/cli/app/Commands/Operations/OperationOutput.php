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
        $modelRoute = self::modelRoute($operation);

        return [
            'Kind' => $operation->kind,
            'Target' => "{$operation->targetType} {$operation->targetId}",
            'Status' => $operation->status,
            'Error code' => $operation->errorCode,
            'Current step' => $operation->currentStep,
            'Step started' => $operation->currentStepStartedAt,
            'Step observed' => $operation->currentStepObservedAt,
            'Failed step' => $operation->failedStep,
            'Error detail' => is_string($operation->error['detail'] ?? null) ? $operation->error['detail'] : null,
            'Result' => $result,
            ...($modelRoute === null ? [] : ['Model route' => $modelRoute]),
            'Cancel requested' => $operation->cancelRequested ? 'yes' : null,
            'Attempts' => $operation->attempts,
            'By' => $operation->triggeredBy,
            'Caller Node' => $operation->callerNodeName ?? ($operation->callerNodeId === null ? null : "Removed Node ($operation->callerNodeId)"),
            'Created' => $operation->createdAt,
            'Started' => $operation->startedAt,
            ...($operation->overdueSince === null ? [] : ['Overdue since' => $operation->overdueSince]),
            'Finished' => $operation->finishedAt,
        ];
    }

    /** The admission-time reason a clone or migrated T3 server cannot reach models. */
    public static function modelRoute(OperationResponse $operation): ?string
    {
        $route = $operation->result['model_route'] ?? null;
        if (! in_array($operation->kind, ['instance.create', 'instance.migrate'], true)
            || ! is_array($route) || ($route['ready'] ?? null) !== false) {
            return null;
        }
        $code = is_string($route['error_code'] ?? null) ? ' ('.$route['error_code'].')' : '';
        $message = is_string($route['message'] ?? null) ? ': '.$route['message'] : '';

        return 'unavailable'.$code.$message;
    }

    /** @return list<string> */
    public static function row(OperationResponse $operation): array
    {
        return [
            (string) $operation->id,
            $operation->kind,
            "{$operation->targetType} {$operation->targetId}",
            $operation->status,
            $operation->triggeredBy ?? '',
            $operation->startedAt ?? $operation->createdAt ?? '',
        ];
    }
}
