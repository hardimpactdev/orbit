<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One removal revision of a managed target and its owed acknowledgement. Gateway and target times stay separate strings
 * with microseconds, so the proof can measure each step ([Timestamps](/reference/conn#timestamps)).
 *
 * @property int $id
 * @property int $conn_managed_target_id
 * @property int $generation
 * @property int $revision
 * @property list<int> $edge_ids
 * @property string $committed_at
 * @property string|null $notice_sent_at
 * @property string|null $notice_suppressed_at
 * @property string|null $ack_kind
 * @property string|null $acknowledged_at
 * @property string|null $notice_received_at
 * @property string|null $fenced_at
 * @property string|null $handlers_closed_at
 * @property string|null $recovered_at
 * @property string|null $superseded_at
 */
final class ConnManagedRevision extends Model
{
    #[\Override]
    public $timestamps = false;

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['conn_managed_target_id', 'generation', 'revision', 'edge_ids', 'committed_at'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['conn_managed_target_id' => 'integer', 'generation' => 'integer', 'revision' => 'integer', 'edge_ids' => 'array'];
    }
}
