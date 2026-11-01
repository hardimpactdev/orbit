<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Routes\RouteExecutionProof;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Stores only the public native snapshot. It performs no Node read or effect validation. */
final readonly class FirewallInspectionProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = [];
        foreach (['backend', 'observed_at', 'error_code'] as $field) {
            if (isset($raw[$field])) {
                $result[$field] = ValidatedData::string($raw[$field]);
            }
        }
        if (isset($raw['live'])) {
            if (! is_array($raw['live']) || ! array_is_list($raw['live'])) {
                throw new \InvalidArgumentException('The native firewall snapshot requires a rule list.');
            }
            $result['live'] = array_map(static fn (mixed $row): array => UfwRuleShape::fromAgentOutput(ValidatedData::object($row))->toAgentInput(), $raw['live']);
        }

        if (isset($raw['comparison'])) {
            $comparison = ValidatedData::object($raw['comparison']);
            $result['comparison'] = [];
            foreach (['live', 'missing'] as $field) {
                $rows = RouteExecutionProof::objects($comparison[$field]);
                $result['comparison'][$field] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip([
                    'name', 'comment', 'action', 'source', 'destination', 'port', 'protocol', 'interface', 'family', 'match',
                ])), $rows);
            }
        }

        return $this->direct->project($operation, $data, $result);
    }
}
