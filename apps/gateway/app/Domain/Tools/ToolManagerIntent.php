<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;

/** Public, immutable recipe input for the existing named materialization commands. */
final readonly class ToolManagerIntent
{
    public function __construct(private VpToolManager $vp) {}

    /** @return array<string, mixed> */
    public function input(Node $node, ToolManagerName $manager, bool $imageProvidesManager = false): array
    {
        if ($node->platform !== 'linux' || $imageProvidesManager) {
            return [];
        }
        $command = match ($manager) {
            ToolManagerName::Vp => 'tool.materialize-vp',
            ToolManagerName::Composer => 'tool.materialize-composer',
            ToolManagerName::Brew => 'tool.materialize-homebrew',
            default => null,
        };
        if ($command === null) {
            return [];
        }
        $input = $manager === ToolManagerName::Vp ? $this->vp->materializeInput($node) : ['user' => $node->user];

        return [...$input, 'materialize_command' => $command, 'materialize_fields' => array_keys($input)];
    }
}
