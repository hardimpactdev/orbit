<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\OperationRun;

final readonly class RuntimeActivationOutcome
{
    public const string ACTIVATED = 'activated';

    public const string FORBIDDEN = 'forbidden';

    public const string NOT_FOUND = 'not_found';

    public const string FAILED = 'failed';

    public const string WAKING = 'waking';

    public function __construct(
        public string $status,
        public ?RuntimeHibernationScope $scope = null,
        public ?OperationRun $operationRun = null,
    ) {}
}
