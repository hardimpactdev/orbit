<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Domain\Instances\InstanceBranchSelection;

final readonly class CreateInstanceData
{
    public function __construct(
        public int $projectId,
        public int $nodeId,
        public string $name,
        public ?string $root,
        public ?string $domain,
        public ?string $branch,
        public ?string $databaseServer = null,
        public ?InstanceBranchSelection $branchSelection = null,
    ) {}

    public function withName(string $name): self
    {
        return new self($this->projectId, $this->nodeId, $name, $this->root, $this->domain, $this->branch, $this->databaseServer, $this->branchSelection);
    }
}
