<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Get a preference value by key.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $preference = static::where('key', $key)->first();

        return $preference ? $preference->value : $default;
    }

    /**
     * Set a preference value.
     */
    public static function setValue(string $key, mixed $value): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Delete a preference.
     */
    public static function deleteKey(string $key): bool
    {
        return static::where('key', $key)->delete() > 0;
    }
}
