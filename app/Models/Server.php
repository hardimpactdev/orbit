<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    protected $fillable = [
        'name',
        'host',
        'user',
        'port',
        'is_local',
        'is_default',
        'metadata',
        'last_connected_at',
    ];

    protected $casts = [
        'is_local' => 'boolean',
        'is_default' => 'boolean',
        'metadata' => 'array',
        'last_connected_at' => 'datetime',
    ];

    public function getSshConnectionString(): string
    {
        if ($this->is_local) {
            return 'local';
        }

        $port = $this->port !== 22 ? "-p {$this->port}" : '';
        return trim("{$this->user}@{$this->host} {$port}");
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first();
    }

    public static function getLocal(): ?self
    {
        return static::where('is_local', true)->first();
    }
}
