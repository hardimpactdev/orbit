<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pairing link the Gateway minted on a T3 server for one Node. The session the Node gets from it
 * retains the label for Conn clients; MCP sessions require bounded source-IP attribution. A held pairing is one
 * the Gateway redeemed itself: it keeps the session for its Node ([Held sessions](/reference/conn#held-sessions)).
 *
 * @property int $id
 * @property int $node_id
 * @property int $conn_environment_id
 * @property string|null $pairing_link_id
 * @property string $client_label
 * @property Carbon|null $expires_at
 * @property string|null $credential
 * @property Carbon|null $issue_started_at
 * @property Carbon|null $revoked_at
 * @property bool $held
 * @property string|null $session
 * @property Carbon|null $session_expires_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Node $node
 * @property-read ConnEnvironment $environment
 */
final class ConnPairing extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 'conn_environment_id', 'pairing_link_id', 'client_label', 'expires_at', 'revoked_at', 'credential', 'issue_started_at', 'held', 'session', 'session_expires_at', 'withdrawn_at'];

    #[\Override]
    protected $hidden = ['credential', 'session'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<ConnEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(ConnEnvironment::class, 'conn_environment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'conn_environment_id' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'issue_started_at' => 'datetime',
            'credential' => 'encrypted',
            'held' => 'boolean',
            'session' => 'encrypted',
            'session_expires_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }
}
