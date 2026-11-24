<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The Gateway admin session of an unregistered host server, which the Gateway still owes a revoke on that server
 * ([Unregister a host server](/reference/conn#unregister-a-host-server)). No server list reads it.
 *
 * @property int $id
 * @property string $environment_id
 * @property string $url
 * @property string $admin_session
 * @property Carbon $admin_session_expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ConnOwedGatewayRevoke extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['environment_id', 'url', 'admin_session', 'admin_session_expires_at'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['admin_session'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['admin_session' => 'encrypted', 'admin_session_expires_at' => 'datetime'];
    }
}
