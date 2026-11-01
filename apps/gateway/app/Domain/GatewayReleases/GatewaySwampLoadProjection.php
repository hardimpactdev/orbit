<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects the native load result; the release runner reads the terminal status. */
final readonly class GatewaySwampLoadProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ['sha' => $operation->input['sha']], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['sha', 'previous', 'workflows']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
