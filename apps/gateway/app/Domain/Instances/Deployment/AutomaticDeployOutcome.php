<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

/** The result of one automatic deployment tick for one Instance. */
final readonly class AutomaticDeployOutcome
{
    public function __construct(
        public int $instanceId,
        public AutomaticDeployResult $result,
        public ?string $commit = null,
        public ?int $operationId = null,
        public ?string $errorCode = null,
        public ?string $message = null,
    ) {}
}
