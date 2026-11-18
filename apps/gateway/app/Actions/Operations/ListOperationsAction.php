<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Collection;

/** One page of the newest operations on Nodes the caller can access, newest first (docs/reference/operations.md#page-the-list). */
final readonly class ListOperationsAction
{
    public const int DefaultLimit = 20;

    public const int MaxLimit = 100;

    /** Every column but the stored input and result, which a row never shows and which can each hold 256 KiB. */
    private const array ListColumns = [
        'id', 'kind', 'target_type', 'target_id', 'node_id', 'state', 'status', 'sequence', 'current_step', 'failed_step', 'error_code', 'attempts',
        'triggered_by', 'cancel_requested_at', 'started_at', 'finished_at', 'created_at', 'updated_at',
    ];

    public function __construct(private NodeAccessAuthorizer $access) {}

    /**
     * Reads one row more than the page, so the caller knows whether a next page exists.
     *
     * @param  array{status?: string, kind?: string, target_type?: string, target_id?: int}  $filters
     * @param  int|null  $before  the cursor: only operations with a lower ID
     * @return array{operations: Collection<int, Operation>, next_cursor: ?string}
     */
    public function execute(Node $consumer, array $filters, int $limit = self::DefaultLimit, ?int $before = null): array
    {
        $rows = Operation::query()
            ->publiclyVisible()
            ->select(self::ListColumns)
            ->addSelect(['input->caller_node_id as listed_caller_node_id', 'result->ready_at as listed_ready_at'])
            ->withCallerNodeName()
            ->when(
                ! $this->access->hasGatewayAuthority($consumer),
                fn ($query) => $query->whereIn('node_id', $this->access->accessibleNodeIds($consumer)),
            )
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status'] ?? null))
            ->when(isset($filters['kind']), static fn ($query) => $query->where('kind', $filters['kind'] ?? null))
            ->when(isset($filters['target_type']), static fn ($query) => $query->where('target_type', $filters['target_type'] ?? null))
            ->when(isset($filters['target_id']), static fn ($query) => $query->where('target_id', $filters['target_id'] ?? null))
            ->when($before !== null, static fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $page = $rows->take($limit)->values()->each(self::listedMembers(...));

        return ['operations' => $page, 'next_cursor' => $rows->count() > $limit ? (string) $page->last()?->id : null];
    }

    /** A row reads one member of the input, the caller Node, and a wake's `ready_at`. */
    private static function listedMembers(Operation $operation): void
    {
        $caller = $operation->getAttribute('listed_caller_node_id');
        $operation->input = is_numeric($caller) ? ['caller_node_id' => (int) $caller] : [];
        $readyAt = $operation->getAttribute('listed_ready_at');
        $operation->result = is_string($readyAt) ? ['ready_at' => $readyAt] : null;
    }
}
