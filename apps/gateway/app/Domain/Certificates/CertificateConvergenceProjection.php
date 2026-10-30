<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Public signing request and leaf-presence projection. Swamp owns the steps. */
final readonly class CertificateConvergenceProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        if ($operation->kind !== 'certificate.converge' || $operation->target_type !== 'node_certificate'
            || $operation->node_id === null || $operation->target_id !== $operation->node_id) {
            throw new ResourceOperationException('operation.direct_invalid', 'A Node-bound certificate intent is required.', 422);
        }

        return Arr::only($operation->input, ['scope', 'domain', 'version', 'root_hash', 'user', 'home', 'agent_sha256']);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = Arr::only(ValidatedData::object($data['result'] ?? []), ['present', 'request_base64', 'error_code']);
        $code = $data['error_code'] ?? null;
        $request = $result['request_base64'] ?? null;
        if ($request !== null) {
            $pem = is_string($request) ? base64_decode($request, true) : false;
            if ($status !== OperationStatus::Running || ! is_string($pem) || strlen($request) > 32768
                || array_keys($result) !== ['request_base64'] || preg_match('/\A-----BEGIN CERTIFICATE REQUEST-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE REQUEST-----\r?\n?\z/D', $pem) !== 1
                || @openssl_csr_get_public_key($pem) === false
                || (isset($operation->state['certificate_request']) && $operation->state['certificate_request'] !== $request)) {
                throw new ResourceOperationException('certificate.request_invalid', 'The public signing request differs from the admitted Node request.', 422);
            }
        } elseif (($status === OperationStatus::Succeeded && $result !== ['present' => true])
            || ($status === OperationStatus::Failed && (! is_string($code) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1 || $result !== ['error_code' => $code]))
            || (in_array($status, [OperationStatus::Running, OperationStatus::Cancelled], true) && $result !== [])) {
            throw new ResourceOperationException('operation.result_invalid', 'Certificate convergence needs verified presence or a bounded refusal.', 422);
        }
        if ($status !== OperationStatus::Failed && $code !== null) {
            throw new ResourceOperationException('operation.result_invalid', 'Only failed certificate results carry a refusal code.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $request): Operation {
            if (is_string($request) && ! $operation->status->terminal()) {
                $operation->update(['state' => [...($operation->state ?? []), 'certificate_request' => $request]]);
            }

            return $this->direct->project($operation, $data, Arr::except($result, ['request_base64']), 'certificate-inspect');
        });
    }
}
