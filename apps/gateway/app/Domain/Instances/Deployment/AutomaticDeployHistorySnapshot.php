<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

final readonly class AutomaticDeployHistorySnapshot
{
    /** @param list<string> $rolledAway */
    public function __construct(public string $version, public int $lastDeploymentId, public array $rolledAway) {}
}
