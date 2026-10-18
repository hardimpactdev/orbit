<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;

final readonly class DoctorNodeContext
{
    public function __construct(
        public Node $node,
        public NodeInspectionData $inspection,
        public bool $inspectionFailed = false,
        public ?DoctorInspectionScope $scope = null,
        public int|string|null $resourceId = null,
    ) {}

    public function withScope(DoctorInspectionScope $scope): self
    {
        return new self($this->node, $this->inspection, $this->inspectionFailed, $scope, $this->resourceId);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     */
    public function select(Builder $query): void
    {
        if ($this->resourceId !== null) {
            $query->whereKey($this->resourceId);
        }
    }
}
