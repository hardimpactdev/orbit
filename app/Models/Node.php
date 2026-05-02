<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Node extends Model
{
    protected $fillable = [
        'name',
        'role',
        'environment',
        'tld',
        'platform',
        'host',
        'wireguard_address',
        'gateway_endpoint',
        'ssh_user',
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
}
