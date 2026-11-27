<?php

declare(strict_types=1);

namespace App\Domain\Engine;

/**
 * One step a definition declares (ADR 0271): a primitive on one Node, or a Gateway step (`node` 0), with the steps it waits
 * for. `target` is the resource the primitive acts on and the executor's lock: the Node itself for a ufw rule. An `onFailure`
 * step runs only after another step failed, and only when its own `after` steps are done; otherwise it is skipped. An
 * `optional` step runs when its `after` steps are done, but its Operation neither waits for it nor fails by it. `features` are
 * inputs the Node's executor must report beside the action before admission routes the Operation to the engine.
 */
final readonly class StepSpec
{
    /**
     * @param  list<string>  $after
     * @param  list<string>  $features
     */
    public function __construct(
        public string $name,
        public int $node,
        public string $action,
        public string $target,
        public array $after = [],
        public int $budgetMs = 60_000,
        public int $resendMs = 5_000,
        public bool $onFailure = false,
        public bool $optional = false,
        public array $features = [],
    ) {}
}
