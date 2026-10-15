<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Operations\OperationStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One named step of an operation. `sequence` is the operation's sequence when the step last changed, so a reader
 * that knows sequence N reads only the steps after it.
 *
 * @property int $id
 * @property int $operation_id
 * @property int $position
 * @property string $name
 * @property OperationStepStatus $status
 * @property bool $resumable
 * @property int $sequence
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error_code
 * @property string|null $output
 * @property bool $output_truncated
 * @property-read Operation $operation
 */
final class OperationStep extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'operation_id',
        'position',
        'name',
        'status',
        'resumable',
        'sequence',
        'started_at',
        'finished_at',
        'error_code',
        'output',
        'output_truncated',
    ];

    /** @return BelongsTo<Operation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => OperationStepStatus::class,
            'resumable' => 'boolean',
            'sequence' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'output_truncated' => 'boolean',
        ];
    }
}
