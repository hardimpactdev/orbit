<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Project only the outcome of this snapshot publication, from Swamp's own run data. */
final readonly class WorkerKnownHostsProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $intent = ValidatedData::object($operation->input['intent']);
        $result = ($data['status'] ?? null) === 'succeeded' && NativeRunResult::stepStatus($raw, 'Write known hosts') === 'succeeded'
            ? ['known_hosts_ready' => true, 'digest' => $intent['digest']]
            : array_intersect_key($raw, array_flip(['error_code']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
