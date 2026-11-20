<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskHandoffType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One handoff of the task loop ([Handoffs](/reference/tasks#handoffs)): the end of one agent run, written once its gate has finished.
 * A row that is not rejected was checked and accepted.
 *
 * @property int $id
 * @property int $task_id the subtask
 * @property int|null $operation_id the copy turn that left the receipt
 * @property TaskHandoffType $type
 * @property array<string, mixed>|null $receipt the receipt as the turn left it; null when it left none or none that parses
 * @property bool $rejected
 * @property string $rejection_reason empty unless rejected
 * @property int|null $task_check_id the handoff check of a delivery
 * @property Carbon|null $created_at
 */
final class TaskHandoff extends Model
{
    public const ?string UPDATED_AT = null;

    #[\Override]
    protected $fillable = ['task_id', 'operation_id', 'type', 'receipt', 'rejected', 'rejection_reason', 'task_check_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TaskHandoffType::class,
            'receipt' => 'array',
            'rejected' => 'boolean',
            'operation_id' => 'integer',
            'task_check_id' => 'integer',
        ];
    }
}
