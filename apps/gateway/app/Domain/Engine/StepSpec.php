<?php

declare(strict_types=1);

namespace App\Domain\Engine;

/**
 * One step a definition declares (ADR 0271): a primitive on one Node, or a Gateway step (`node` 0), with the steps it waits
 * for. `target` is the resource the primitive acts on and the executor's lock: the Node itself for a ufw rule.
 */
final readonly class StepSpec
{
    /** @param list<string> $after */
    public function __construct(
        public string $name,
        public int $node,
        public string $action,
        public string $target,
        public array $after = [],
        public int $budgetMs = 60_000,
        public int $resendMs = 5_000,
    ) {}
}
