<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Operations\OperationStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One long operation that a job on the `operations` queue runs as named steps (docs/reference/operations.md).
 * `lock_key` holds the target from the start request to the terminal status; `state` keeps the values that one
 * step hands to the steps after it, so a resumed operation continues where it stopped. `state` never leaves the
 * Gateway.
 *
 * @property int $id
 * @property string $kind
 * @property string|null $idempotency_key Never prune a keyed operation when ADR 0218 adds retention.
 * @property string|null $idempotency_fingerprint
 * @property string $target_type
 * @property int $target_id
 * @property int|null $node_id
 * @property string|null $lock_key
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $state
 * @property array<string, mixed>|null $result
 * @property OperationStatus $status
 * @property int $sequence
 * @property string|null $current_step
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property int $attempts
 * @property string|null $worker
 * @property string|null $triggered_by
 * @property string|null $request_id
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, OperationStep> $steps
 */
final class Operation extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'kind',
        'idempotency_key',
        'idempotency_fingerprint',
        'target_type',
        'target_id',
        'node_id',
        'lock_key',
        'input',
        'state',
        'result',
        'status',
        'sequence',
        'current_step',
        'failed_step',
        'error_code',
        'attempts',
        'worker',
        'triggered_by',
        'request_id',
        'cancel_requested_at',
        'dispatched_at',
        'started_at',
        'finished_at',
    ];

    /** @return HasMany<OperationStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(OperationStep::class)->orderBy('position');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'node_id' => 'integer',
            'input' => 'array',
            'state' => 'array',
            'result' => 'array',
            'status' => OperationStatus::class,
            'sequence' => 'integer',
            'attempts' => 'integer',
            'cancel_requested_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
