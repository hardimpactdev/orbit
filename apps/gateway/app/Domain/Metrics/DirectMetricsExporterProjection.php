<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Safe admitted Node inputs and bounded result projection; no execution. */
final readonly class DirectMetricsExporterProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $this->assertOperation($operation);
        $input = $operation->input;
        $pin = $input['agent_sha256'] ?? null;
        if (! is_string($pin) || preg_match('/\A[a-f0-9]{64}\z/D', $pin) !== 1) {
            throw new ResourceOperationException('metrics.agent_pin_invalid', 'The admitted Metrics agent pin is invalid.', 422);
        }
        foreach (['address', 'metrics_address'] as $field) {
            if (! is_string($input[$field] ?? null) || filter_var($input[$field], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('metrics.exporter_address_invalid', 'The admitted Metrics addresses are invalid.', 422);
            }
        }

        return ['agent_sha256' => $pin, 'address' => $input['address'], 'metrics_address' => $input['metrics_address']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->assertOperation($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['changed', 'error_code']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if ((array_key_exists('changed', $result) && ! is_bool($result['changed']))
            || (isset($result['error_code']) && (! is_string($result['error_code']) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $result['error_code']) !== 1))) {
            throw new ResourceOperationException('operation.result_invalid', 'The exporter result has an invalid field.', 422);
        }
        if ($status === OperationStatus::Succeeded && (($result['changed'] ?? null) !== true || $code !== null || isset($result['error_code']))) {
            throw new ResourceOperationException('operation.result_invalid', 'The exporter has no successful Swamp result.', 422);
        }
        if ($status === OperationStatus::Failed && ($code === null || ($result['error_code'] ?? null) !== $code)) {
            throw new ResourceOperationException('operation.result_invalid', 'The exporter refusal needs its matching error code.', 422);
        }

        return $this->direct->project($operation, $data, $result, str_replace(['metrics.', '_'], ['', '-'], $operation->kind));
    }

    private function assertOperation(Operation $operation): void
    {
        if (! DirectOperation::owns($operation) || $operation->node_id === null
            || $operation->kind !== 'metrics.node_exporter_converge') {
            throw new ResourceOperationException('operation.direct_invalid', 'A Node-bound direct Metrics exporter intent is required.', 422);
        }
    }
}
