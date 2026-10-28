<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Collection;

/** The 50 newest operations on Nodes the caller can access, newest first. */
final readonly class ListOperationsAction
{
    public const int Limit = 50;

    public function __construct(private NodeAccessAuthorizer $access) {}

    /**
     * @param  array{status?: string, kind?: string, target_type?: string, target_id?: int}  $filters
     * @return Collection<int, Operation>
     */
    public function execute(Node $consumer, array $filters): Collection
    {
        return Operation::query()
            ->withCallerNodeName()
            ->when(
                ! $this->access->hasGatewayAuthority($consumer),
                fn ($query) => $query->whereIn('node_id', $this->access->accessibleNodeIds($consumer)),
            )
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status'] ?? null))
            ->when(isset($filters['kind']), static fn ($query) => $query->where('kind', $filters['kind'] ?? null))
            ->when(isset($filters['target_type']), static fn ($query) => $query->where('target_type', $filters['target_type'] ?? null))
            ->when(isset($filters['target_id']), static fn ($query) => $query->where('target_id', $filters['target_id'] ?? null))
            ->orderByDesc('id')
            ->limit(self::Limit)
            ->get();
    }
}
