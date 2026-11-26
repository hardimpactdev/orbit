<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolStatus;
use App\Support\ValidatedData;
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
 * @property array{state?: int, owner?: int, version?: int, suspended?: array<string, mixed>}|null $receipts
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
        $this->receiveAt(self::order($operation), $state, $owned, $version, $observed);
    }

    /**
     * An explicit adoption is newer than every receipt of an Operation admitted before it, the newest one included: it orders after
     * the latest Operation, so no late receipt of that Operation sets back what it recorded.
     *
     * @param  array<string, mixed>  $state
     */
    public function adopt(array $state, ?string $version): void
    {
        $this->receiveAt(self::order(ValidatedData::integer(Operation::query()->max('id') ?? 0)) + 1, $state, true, $version, true);
    }

    /** The order of an Operation's receipt among the Tool's changes; an adoption sits between two Operations. */
    public static function order(int $operation): int
    {
        return $operation * 2;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function receiveAt(int $order, array $state, ?bool $owned, ?string $version, bool $observed): void
    {
        $operation = $order;
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

    /**
     * Sets aside the Tool's uninstall authority while an Operation's outcome is unknown, keeping what it was so that proof the Operation
     * never reached the package can give it back (resume). The claim kept is the row's own, whatever its admission recorded.
     *
     * @param  array<string, mixed>  $state
     */
    public function suspend(int $operation, array $state): void
    {
        $kept = ['operation' => $operation, 'owned' => $this->owned, 'status' => $this->status->value, 'failed_operation' => $this->failed_operation?->value,
            'error_code' => $this->error_code];
        $this->receive($operation, $state, owned: false);
        $this->update(['receipts' => [...($this->receipts ?? []), 'suspended' => $kept]]);
    }

    /** Gives back what this Operation's suspension set aside, unless a newer receipt changed the row since. */
    public function resume(int $operation): void
    {
        $suspended = $this->receipts['suspended'] ?? null;
        if (! is_array($suspended) || ($suspended['operation'] ?? null) !== $operation) {
            return;
        }
        $receipts = $this->receipts ?? [];
        unset($receipts['suspended']);
        $this->update(['receipts' => $receipts]);
        $this->receive($operation, ['status' => ToolStatus::from(is_string($suspended['status'] ?? null) ? $suspended['status'] : ToolStatus::Installed->value),
            'failed_operation' => is_string($suspended['failed_operation'] ?? null) ? ToolOperation::from($suspended['failed_operation']) : null,
            'error_code' => $suspended['error_code'] ?? null], owned: ($suspended['owned'] ?? false) === true);
    }

    /** No newer Operation's receipt changed this row: an Operation may still act on it as a whole, deleting it for example. */
    public function currentFor(int $operation): bool
    {
        $operation = self::order($operation);

        return max([0, ...array_filter(array_values($this->receipts ?? []), 'is_int')]) <= $operation;
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
