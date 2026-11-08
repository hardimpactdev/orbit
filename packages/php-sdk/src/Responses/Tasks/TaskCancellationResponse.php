<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

use Orbit\Sdk\Responses\Operations\OperationResponse;

final readonly class TaskCancellationResponse
{
    public function __construct(public OperationResponse|SubtaskResponse $result) {}
}
