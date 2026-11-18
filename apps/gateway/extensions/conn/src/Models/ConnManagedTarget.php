<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One manually enrolled lab target of [Managed grant revocation](/reference/conn#managed-grant-revocation). It holds
 * only the digest of the target's service credential.
 *
 * @property int $id
 * @property int $conn_environment_id
 * @property string $environment_id
 * @property int $node_id
 * @property int $generation
 * @property int $revision
 * @property string $credential_digest
 */
final class ConnManagedTarget extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['conn_environment_id', 'environment_id', 'node_id', 'generation', 'revision', 'credential_digest'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['credential_digest'];

    public function channel(?int $generation = null): string
    {
        return 'private-conn-target.'.$this->id.'.'.($generation ?? $this->generation);
    }

    /** @return HasMany<ConnManagedRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ConnManagedRevision::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['conn_environment_id' => 'integer', 'node_id' => 'integer', 'generation' => 'integer', 'revision' => 'integer'];
    }
}
