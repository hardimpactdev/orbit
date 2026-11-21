<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A clone that a Conn device reserved when its user opened a new thread (docs/reference/conn.md#reserve-a-clone).
 *
 * @property string $id
 * @property int $project_id
 * @property int|null $instance_id
 * @property int $node_id
 * @property int|null $conn_profile_id
 * @property int|null $conn_pairing_id
 * @property int|null $create_operation_id
 * @property int|null $finalize_operation_id
 * @property int|null $destroy_operation_id
 * @property string $state
 * @property Carbon $expires_at
 * @property-read Instance|null $instance
 * @property-read ConnPairing|null $pairing
 * @property-read Operation|null $createOperation
 * @property-read Operation|null $finalizeOperation
 */
final class ConnReservation extends Model
{
    use HasUuids;

    /** Neither finalized, released nor expired: its create may still run. */
    public const string Open = 'open';

    public const string Finalized = 'finalized';

    public const string Released = 'released';

    public const string Expired = 'expired';

    protected $fillable = [
        'project_id', 'instance_id', 'node_id', 'conn_profile_id', 'conn_pairing_id',
        'create_operation_id', 'finalize_operation_id', 'destroy_operation_id', 'state', 'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'instance_id' => 'integer',
            'node_id' => 'integer',
            'conn_profile_id' => 'integer',
            'conn_pairing_id' => 'integer',
            'create_operation_id' => 'integer',
            'finalize_operation_id' => 'integer',
            'destroy_operation_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    /** @return BelongsTo<ConnPairing, $this> */
    public function pairing(): BelongsTo
    {
        return $this->belongsTo(ConnPairing::class, 'conn_pairing_id');
    }

    /** @return BelongsTo<Operation, $this> */
    public function createOperation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'create_operation_id');
    }

    /** @return BelongsTo<Operation, $this> */
    public function finalizeOperation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'finalize_operation_id');
    }
}
