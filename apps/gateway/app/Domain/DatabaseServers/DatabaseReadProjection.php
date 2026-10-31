<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class DatabaseReadProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = DatabaseExecutionIntent::recorded(app(DatabaseIntentSource::class)->ownerId($operation));
        $admission = $operation->input;
        $input = ['driver' => $admission['driver'], 'mode' => $admission['mode'], 'table' => $admission['table']];
        if ($admission['driver'] === 'sqlite') {
            $command = $intent->payload['commands']['database-apply']['input'];
            $input += ['owner' => $command['owner'], 'path' => $command['path']];
        }

        return $input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($data['result'] ?? []));
    }
}
