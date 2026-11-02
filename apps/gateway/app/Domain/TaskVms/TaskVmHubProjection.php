<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Support\ValidatedData;

/** No result step: the end check proved the exact live rules, so the result comes from the captured intent. */
final readonly class TaskVmHubProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** The end check: every expected control and Router rule is a line of the live chain. */
    public const string EndCheck = 'Confirm the filter';

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = [];
        if (($data['status'] ?? null) === OperationStatus::Succeeded->value) {
            if (NativeRunResult::stepStatus(ValidatedData::object($data['result'] ?? []), self::EndCheck) !== 'succeeded') {
                throw new ResourceOperationException('operation.result_invalid', 'A succeeded hub run needs its passed end check.', 422);
            }
            $intent = ValidatedData::object($operation->input['intent']);
            $result = ['ready' => true, 'node_id' => ValidatedData::integer($intent['node_id']),
                'control_ssh_source' => ValidatedData::string($intent['source']), 'control_ssh_port' => 22,
                'router_sources' => ValidatedData::stringList(ValidatedData::object($intent['policy'])['routers']), 'router_web_ports' => [80, 443]];
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
