<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Safe admitted probe input and bounded status projection. No Node execution. */
final readonly class RouteVerificationProjection implements DirectOperationProjection
{
    public const array Kinds = ['route.workload_verify', 'route.public_verify'];

    public function __construct(private DirectOperation $direct, private OperationExecutionContract $contract) {}

    public function input(Operation $operation): array
    {
        if (! in_array($operation->kind, self::Kinds, true) || $operation->target_type !== 'route_effect' || $operation->node_id === null) {
            throw new ResourceOperationException('operation.direct_invalid', 'A Node-bound Route verification is required.', 422);
        }
        $input = ValidatedData::object($operation->input['verification'] ?? []);
        if (! in_array($input['protocol'] ?? null, ['tls', 'http', 'skip'], true)
            || ! is_string($input['agent_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $input['agent_sha256']) !== 1) {
            throw new ResourceOperationException('route.verification_invalid', 'The admitted Route probe is missing.', 422);
        }

        $probe = $input['probe'] ?? null;
        if ($input['protocol'] === 'skip' ? $probe !== null : (! is_array($probe)
            || ! is_string($probe['address'] ?? null) || filter_var($probe['address'], FILTER_VALIDATE_IP) === false
            || ! is_int($probe['port'] ?? null) || $probe['port'] < 1 || $probe['port'] > 65535
            || ! is_string($probe['domain'] ?? null) || RouteDomain::validate($probe['domain']) !== $probe['domain'])) {
            throw new ResourceOperationException('route.verification_invalid', 'The admitted Route probe is invalid.', 422);
        }

        return ['protocol' => $input['protocol'], 'probe' => is_array($probe) ? Arr::only($probe, ['address', 'port', 'domain']) : null,
            'agent_sha256' => $input['agent_sha256']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        $result = $this->contract->result($operation, Arr::only(ValidatedData::object($data['result'] ?? []), ['ready', 'error_code']));
        if (($status === OperationStatus::Succeeded && $result !== ['ready' => true])
            || ($status === OperationStatus::Failed && (! is_string($data['error_code'] ?? null)
                || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $data['error_code']) !== 1
                || $result !== ['error_code' => $data['error_code']]))
            || ($status !== OperationStatus::Failed && ($data['error_code'] ?? null) !== null)
            || (in_array($status, [OperationStatus::Running, OperationStatus::Cancelled], true) && $result !== [])) {
            throw new ResourceOperationException('operation.result_invalid', 'Route verification needs its bounded ready result or refusal.', 422);
        }

        return $this->direct->project($operation, $data, $result, 'route-verified');
    }
}
