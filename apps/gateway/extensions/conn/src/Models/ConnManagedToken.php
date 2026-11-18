<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A managed token of one caller Node for one managed target, stored as its digest ([Credentials](/reference/conn#credentials)).
 *
 * @property int $id
 * @property int $conn_managed_target_id
 * @property int $generation
 * @property int $caller_node_id
 * @property int $node_access_id
 * @property string $token_digest
 * @property list<string> $scopes
 * @property Carbon $expires_at
 * @property int|null $revoked_revision
 */
final class ConnManagedToken extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['conn_managed_target_id', 'generation', 'caller_node_id', 'node_access_id', 'token_digest', 'scopes', 'expires_at'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['token_digest'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'conn_managed_target_id' => 'integer', 'generation' => 'integer', 'caller_node_id' => 'integer',
            'node_access_id' => 'integer', 'scopes' => 'array', 'expires_at' => 'datetime', 'revoked_revision' => 'integer',
        ];
    }
}
