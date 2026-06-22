<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $custom_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReleaseManifestSource extends Model
{
    #[\Override]
    protected $fillable = [
        'custom_url',
    ];

    public static function current(): self
    {
        $record = self::query()->first();

        if ($record === null) {
            $record = self::query()->create([]);
        }

        return $record;
    }
}
