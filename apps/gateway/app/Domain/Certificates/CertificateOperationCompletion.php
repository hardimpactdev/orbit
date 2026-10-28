<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

final readonly class CertificateOperationCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        if ($operation->kind === 'certificate.converge') {
            $runs = $operation->state['executor_runs'] ?? [];
            $request = $runs['certificate-request']['result'] ?? [];
            if (($request['state'] ?? null) === 'current') {
                return;
            }
            if (($request['state'] ?? null) !== 'request'
                || ! is_string($request['request_base64'] ?? null)
                || ($runs['certificate-sign']['result']['signed'] ?? false) !== true
                || ! is_array($runs['certificate-publish']['result'] ?? null)
                || isset($runs['certificate-publish']['result']['error_code'])) {
                throw new ResourceOperationException('operation.completion_unproven', 'The leaf requires proven request, signature, publication and inspection receipts.', 409);
            }
        }
    }
}
