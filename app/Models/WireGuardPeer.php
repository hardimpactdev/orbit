<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WireGuardPeer extends Model
{
    use HasFactory;

    protected $table = 'wireguard_peers';

    protected $fillable = [
        'node_id',
        'public_key',
        'private_key',
        'pre_shared_key',
        'allowed_ips',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
