<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Models\AptRepository;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;

final readonly class AdmittedToolPackage
{
    /** @param list<string> $protected */
    public function __construct(
        private Node $node,
        private ToolManagerName $manager,
        private string $package,
        private ToolDefinition $definition,
        private ?Tool $tool,
        private ?ToolManagerRecord $record,
        private array $protected,
        private ?AptRepository $repository = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'node_id' => $this->node->id, 'manager' => $this->manager->value, 'package' => $this->package,
            'user' => $this->node->user, 'platform' => $this->node->platform, 'architecture' => $this->node->architecture,
            'version_constraint' => $this->definition->constraint, 'desired_version' => $this->definition->version,
            // The referenced repository's definition: the run pins to its origins and reads its package index.
            'repository' => $this->repository?->definition, 'id' => $this->tool?->id, 'manager_id' => $this->record?->id,
            'tool_status' => $this->tool?->status->value, 'installed_version' => $this->tool?->installed_version, 'owned' => $this->tool->owned ?? false,
            'protected' => $this->protected,
        ];
    }
}
