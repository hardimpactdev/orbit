<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $node_id
 * @property int|null $apt_repository_id
 * @property-read AptRepository|null $aptRepository
 * @property int $tool_manager_id
 * @property string $package
 * @property string|null $version_constraint
 * @property string|null $desired_version
 * @property array<string, mixed>|null $desired_repository
 * @property ToolStatus $status
 * @property string|null $installed_version
 * @property ToolOperation|null $failed_operation
 * @property string|null $error_code
 * @property bool $owned
 * @property array{state?: int, owner?: int, version?: int}|null $receipts
 * @property-read Node $node
 * @property-read ToolManagerRecord $manager
 */
final class Tool extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'node_id',
        'tool_manager_id',
        'package',
        'apt_repository_id',
        'version_constraint',
        'desired_version',
        'desired_repository',
        'status',
        'installed_version',
        'failed_operation',
        'error_code',
        'owned',
        'receipts',
    ];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<ToolManagerRecord, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(ToolManagerRecord::class, 'tool_manager_id');
    }

    /**
     * A removal was requested and has not finished: the row only lets `tool:remove` retry the cleanup. It is no membership, so
     * convergence, apt preferences and the catalog leave it out (docs/reference/tools.md#remove-a-tool).
     */
    public function withdrawn(): bool
    {
        return $this->status === ToolStatus::Failed && $this->failed_operation === ToolOperation::Remove;
    }

    /**
     * Applies what one Operation's receipt shows, field by field, unless a newer Operation's receipt already changed that field: the
     * row's state (`status`, `failed_operation`, `error_code` and other columns in `$state`), its ownership, and its installed version.
     * A newer request that changed nothing, refused before any package step or cancelled before its run, never supersedes an older
     * receipt (docs/reference/tools.md#ownership).
     *
     * @param  array<string, mixed>  $state
     * @param  bool|null  $owned  only when the receipt changed ownership: an acquisition, a release, or a restored claim
     * @param  bool  $observed  whether the receipt observed the installed version, absent included
     */
    public function receive(int $operation, array $state = [], ?bool $owned = null, ?string $version = null, bool $observed = false): void
    {
        $receipts = $this->receipts ?? [];
        $changes = [];
        if ($state !== [] && $operation >= ($receipts['state'] ?? 0)) {
            $changes = $state;
            $receipts['state'] = $operation;
        }
        if ($owned !== null && $operation >= ($receipts['owner'] ?? 0)) {
            $changes['owned'] = $owned;
            $receipts['owner'] = $operation;
        }
        if ($observed && $operation >= ($receipts['version'] ?? 0)) {
            $changes['installed_version'] = $version;
            $receipts['version'] = $operation;
        }
        if ($changes !== []) {
            $this->update([...$changes, 'receipts' => $receipts]);
        }
    }

    /** No newer Operation's receipt changed this row: an Operation may still act on it as a whole, deleting it for example. */
    public function currentFor(int $operation): bool
    {
        return max([0, ...array_values($this->receipts ?? [])]) <= $operation;
    }

    /** @return BelongsTo<AptRepository, $this> */
    public function aptRepository(): BelongsTo
    {
        return $this->belongsTo(AptRepository::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ToolStatus::class,
            'receipts' => 'array',
            'failed_operation' => ToolOperation::class,
            'desired_repository' => 'array',
            'owned' => 'boolean',
        ];
    }
}
