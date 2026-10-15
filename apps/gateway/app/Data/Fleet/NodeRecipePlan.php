<?php

declare(strict_types=1);

namespace App\Data\Fleet;

/** Explicit pins only: apt resolves their dependencies against the same snapshot at apply time. */
final readonly class NodeRecipePlan
{
    /**
     * @param  list<string>  $roles
     * @param  array<string, array{source: string, version: string, installed: ?string, changed: bool, status: string, refusal?: string}>  $tools
     * @param  list<string>  $actions
     */
    public function __construct(
        public int $nodeId,
        public string $recipe,
        public string $snapshot,
        public array $roles,
        public array $tools,
        public array $actions = [],
    ) {}
}
