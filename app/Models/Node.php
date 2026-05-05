<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Node extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'role',
        'environment',
        'tld',
        'platform',
        'host',
        'wireguard_address',
        'gateway_endpoint',
        'public_ipv4',
        'public_ipv6',
        'ssh_user',
        'user',
        'orbit_path',
        'status',
        'is_local',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_local' => 'boolean',
        ];
    }

    public function consumingNodes(): BelongsToMany
    {
        return $this->belongsToMany(
            related: self::class,
            table: 'node_access',
            foreignPivotKey: 'serving_node_id',
            relatedPivotKey: 'consumer_node_id',
        );
    }

    public function servingNodes(): BelongsToMany
    {
        return $this->belongsToMany(
            related: self::class,
            table: 'node_access',
            foreignPivotKey: 'consumer_node_id',
            relatedPivotKey: 'serving_node_id',
        );
    }
}
