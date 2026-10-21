<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property ?int $actor_node_id
 * @property string $idempotency_key
 * @property string $kind
 * @property ?string $app_name
 * @property string $epoch
 * @property ?int $credential_generation
 * @property int $revision
 * @property array<string, mixed> $result
 */
final class WebSocketRegistryOperation extends Model
{
    #[\Override]
    protected $table = 'websocket_registry_operations';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['credential_generation' => 'integer', 'revision' => 'integer', 'result' => 'array'];
    }
}
