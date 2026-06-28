<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property bool $enabled
 * @property Carbon|null $enabled_at
 */
final class GatewayExtension extends Model
{
    #[\Override]
    protected $fillable = [
        'slug',
        'enabled',
        'enabled_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
        ];
    }
}
