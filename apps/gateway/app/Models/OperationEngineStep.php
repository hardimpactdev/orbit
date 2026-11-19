<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an Operation on the Gateway state machine (ADR 0271): a primitive on one Node, or a step on the Gateway
 * (`node_id` 0), with the steps it waits for.
 *
 * @property int $id
 * @property int $operation_id
 * @property string $name
 * @property int $node_id
 * @property string $action
 * @property string $target
 * @property list<string> $after
 * @property string $status pending, sent, done or failed
 * @property int $sends
 * @property int $budget_ms
 * @property int $resend_ms
 * @property int|null $first_sent_ms
 * @property int|null $resend_at_ms
 * @property int|null $done_ms
 * @property string|null $output
 * @property string|null $error_code
 * @property int $late
 */
final class OperationEngineStep extends Model
{
    protected $fillable = ['operation_id', 'name', 'node_id', 'action', 'target', 'after', 'status', 'sends', 'budget_ms', 'resend_ms',
        'first_sent_ms', 'resend_at_ms', 'done_ms', 'output', 'error_code', 'late'];

    /** @return BelongsTo<Operation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    protected function casts(): array
    {
        return ['after' => 'array', 'node_id' => 'integer', 'sends' => 'integer', 'budget_ms' => 'integer', 'resend_ms' => 'integer',
            'first_sent_ms' => 'integer', 'resend_at_ms' => 'integer', 'done_ms' => 'integer', 'late' => 'integer'];
    }
}
