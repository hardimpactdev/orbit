<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Safe capture and bounded root result only; no host execution or child admission. */
final readonly class MetricsFleetProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->input['metrics_fleet'] ?? []);
        MetricsFleetCapture::assertDigest($plan);
        if ($operation->node_id !== ($plan['metrics_node_id'] ?? null) || $operation->target_id !== $operation->node_id || $operation->target_type !== 'metrics') {
            $this->refuse();
        }

        return ['metrics_fleet' => $plan, ...(isset($operation->input['canary_node_ids']) ? ['canary_node_ids' => $operation->input['canary_node_ids']] : [])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $plan = ValidatedData::object($this->input($operation)['metrics_fleet']);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $ids = ValidatedData::integerList(array_column(MetricsFleetCapture::members($plan), 'node_id'));
        $missing = array_column(array_filter(MetricsFleetCapture::members($plan), static fn (array $node): bool => $node['worker'] === false), 'node_id');
        if ($status->terminal()) {
            if (array_diff(array_keys($result), ['ready', 'error_code', 'missing_worker_ids', 'verified_node_ids', 'restored_node_ids', 'unknown_node_ids', 'runtime_committed']) !== []
                || ! is_bool($result['ready'] ?? null) || ! is_bool($result['runtime_committed'] ?? null)) {
                $this->refuse();
            }
            foreach (['missing_worker_ids', 'verified_node_ids', 'restored_node_ids', 'unknown_node_ids'] as $field) {
                $values = ValidatedData::integerList($result[$field] ?? null);
                if (count($values) !== count(array_unique($values)) || array_diff($values, $ids) !== []) {
                    $this->refuse();
                }
            }
            if ($result['missing_worker_ids'] !== $missing) {
                $this->refuse();
            }
            if ($status === OperationStatus::Succeeded && ($missing !== [] || $result['ready'] !== true || $result['unknown_node_ids'] !== []
                || $result['restored_node_ids'] !== [] || $result['runtime_committed'] !== true || $result['verified_node_ids'] !== $ids || isset($result['error_code']) || ($data['error_code'] ?? null) !== null)) {
                $this->refuse();
            }
            if ($status === OperationStatus::Failed && ($result['ready'] !== false || ! is_string($result['error_code'] ?? null)
                || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $result['error_code']) !== 1 || $result['error_code'] !== ($data['error_code'] ?? null))) {
                $this->refuse();
            }
        } elseif ($result !== []) {
            $this->refuse();
        }

        return $this->direct->project($operation, $data, $result, 'metrics-fleet');
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'Metrics needs the complete admitted fleet observation.', 422);
    }
}
