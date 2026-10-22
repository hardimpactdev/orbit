<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use App\Infrastructure\MachineSoftware\RecipeLoader;

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

    public function changesIncus(): bool
    {
        foreach (RecipeLoader::IncusPackages as $name) {
            if (($this->tools[$name]['changed'] ?? false) === true) {
                return true;
            }
        }

        return array_intersect($this->actions, ['retire_incus_source', 'pin_incus', 'remove_incus_agent']) !== [];
    }
}
