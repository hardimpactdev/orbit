<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
