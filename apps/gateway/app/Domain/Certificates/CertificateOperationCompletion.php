<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteExecutionProof;
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
            $request = RouteExecutionProof::result($operation, 'certificate-request');
            if (($request['state'] ?? null) === 'current') {
                return;
            }
            if (($request['state'] ?? null) !== 'request'
                || ! is_string($request['request_base64'] ?? null)
                || (data_get($operation->state, 'executor_runs.certificate-sign.result.signed', false)) !== true
                || ! is_array(data_get($operation->state, 'executor_runs.certificate-publish.result'))
                || isset(RouteExecutionProof::result($operation, 'certificate-publish')['error_code'])) {
                throw new ResourceOperationException('operation.completion_unproven', 'The leaf requires proven request, signature, publication and inspection receipts.', 409);
            }
        }
    }
}
