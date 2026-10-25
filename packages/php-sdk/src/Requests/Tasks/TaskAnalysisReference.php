<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

/** Optional native evidence reference; the Gateway owns usability and freshness. */
final readonly class TaskAnalysisReference
{
    public function __construct(public int $operation, public string $planSha256) {}

    /** @return array{operation: int, plan_sha256: string} */
    public function toArray(): array
    {
        return ['operation' => $this->operation, 'plan_sha256' => $this->planSha256];
    }
}
