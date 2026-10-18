<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final readonly class DoctorNodeContext
{
    public function __construct(
        public Node $node,
        public NodeInspectionData $inspection,
        public bool $inspectionFailed = false,
        public ?DoctorInspectionScope $scope = null,
        public ?DoctorResources $resources = null,
    ) {}

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $rows
     * @return Collection<int, TModel>
     */
    public function select(Collection $rows, DoctorResourceKind $kind = DoctorResourceKind::Rows, string $key = 'id'): Collection
    {
        if ($this->resources === null) {
            return $rows;
        }
        if ($this->resources->kind !== $kind) {
            return $rows->slice(0, 0);
        }
        if ($kind === DoctorResourceKind::Environments) {
            return $rows;
        }

        return $rows->whereIn($key, $this->resources->ids)->values();
    }

    public function withScope(DoctorInspectionScope $scope): self
    {
        return new self($this->node, $this->inspection, $this->inspectionFailed, $scope, $this->resources);
    }
}
