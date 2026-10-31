<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects the shared native terminal reader's public result; performs no host work. */
final readonly class NativeCaddyPublicationProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => app(NativeCaddyPublicationInput::class)->forOperation($operation), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['ready', 'result', 'version', 'error_code', 'stage']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
