<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $role
 * @property string|null $environment
 * @property string|null $tld
 * @property string|null $platform
 * @property string $host
 * @property string|null $wireguard_address
 * @property string|null $gateway_endpoint
 * @property string|null $public_ipv4
 * @property string|null $public_ipv6
 * @property array<string, mixed>|null $agent_ide_config
 * @property string $ssh_user
 * @property string|null $user
 * @property string $orbit_path
 * @property string $status
 * @property bool $is_local
 */
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
        'agent_ide_config',
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
            'agent_ide_config' => 'array',
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
