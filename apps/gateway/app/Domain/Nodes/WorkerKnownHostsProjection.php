<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Project only the outcome of this snapshot publication. */
final readonly class WorkerKnownHostsProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip([
            'known_hosts_ready', 'digest', 'changed', 'path', 'error_code',
        ]));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
