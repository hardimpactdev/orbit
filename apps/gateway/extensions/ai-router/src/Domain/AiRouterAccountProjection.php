<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use Illuminate\Support\Arr;

/** Safe intent and native result only; collection remains the observer's job. */
final readonly class AiRouterAccountProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return Arr::only($operation->input, ['origin', 'account', 'disabled']);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = ($data['status'] ?? null) === 'succeeded'
            ? Arr::only($operation->input, ['account', 'disabled']) : [];

        return $this->direct->project($operation, $data, $result);
    }
}
