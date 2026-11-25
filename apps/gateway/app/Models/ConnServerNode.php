<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Conn Server that one Node runs, as the Conn Server rollout reaches it (docs/reference/development-containers.md#conn-server-rollout).
 *
 * @property int $id
 * @property int $node_id
 * @property string $platform `mac` or `linux`.
 * @property string $path The `.app` bundle on a Mac, which may start with `~/`; the `current` link of the install folder on Linux.
 * @property string $service The LaunchAgent label on a Mac; the systemd unit on Linux.
 * @property int $port The port of its `/health` on 127.0.0.1.
 * @property string $user The user that runs it; on a Mac, the SSH user whose gui domain holds the LaunchAgent.
 * @property bool $canary
 * @property bool $notify_before_restart
 * @property bool $tool_withdrawn
 * @property-read Node $node
 */
final class ConnServerNode extends Model
{
    #[\Override]
    protected $guarded = ['id'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return ['port' => 'integer', 'canary' => 'boolean', 'notify_before_restart' => 'boolean', 'tool_withdrawn' => 'boolean'];
    }
}
