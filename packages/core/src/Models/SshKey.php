<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $public_key
 * @property bool $is_default
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class SshKey extends Model
{
    protected $fillable = ['name', 'public_key', 'is_default'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first()
            ?? static::first();
    }

    public function setAsDefault(): void
    {
        static::where('is_default', true)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }

    public function getKeyTypeAttribute(): string
    {
        $parts = explode(' ', $this->public_key);

        return $parts[0];
    }
}
