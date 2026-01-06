<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    const STATUS_PROVISIONING = 'provisioning';

    const STATUS_ACTIVE = 'active';

    const STATUS_ERROR = 'error';

    protected $fillable = [
        'name',
        'host',
        'user',
        'port',
        'is_local',
        'is_default',
        'metadata',
        'last_connected_at',
        'status',
        'provisioning_log',
        'provisioning_error',
        'provisioning_step',
        'provisioning_total_steps',
    ];

    protected $casts = [
        'is_local' => 'boolean',
        'is_default' => 'boolean',
        'metadata' => 'array',
        'last_connected_at' => 'datetime',
        'provisioning_log' => 'array',
    ];

    public function isProvisioning(): bool
    {
        return $this->status === self::STATUS_PROVISIONING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

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
