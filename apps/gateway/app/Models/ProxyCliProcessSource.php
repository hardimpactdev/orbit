<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Extension-owned collector activation state, staged here until the ProxyCli namespace move.
 *
 * @property int $process_id
 * @property ?string $source_revision
 * @property bool $activation_pending
 */
final class ProxyCliProcessSource extends Model
{
    #[\Override]
    protected $table = 'ai_router_process_sources';

    #[\Override]
    protected $primaryKey = 'process_id';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    public $timestamps = false;

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['process_id', 'source_revision', 'activation_pending'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activation_pending' => 'boolean'];
    }
}
