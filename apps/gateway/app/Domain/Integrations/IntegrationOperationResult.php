<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** AwaitOperation returns every terminal outcome; callers must prove success before continuing. */
final readonly class IntegrationOperationResult
{
    public static function requireSucceeded(Operation $operation): void
    {
        if ($operation->status !== OperationStatus::Succeeded) {
            throw new ResourceOperationException($operation->error_code ?? 'integration.operation_failed',
                'The integration Operation did not succeed.', 409, details: ['operation_id' => $operation->id]);
        }
    }
}
