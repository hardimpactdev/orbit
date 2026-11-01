<?php

declare(strict_types=1);

namespace App\Domain\Operations;

interface ScheduledResultProjection
{
    public function activate(): void;

    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $result
     */
    public function applyScheduled(string $workflowId, array $run, array $result): void;
}
