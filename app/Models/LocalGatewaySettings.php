<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalGatewaySettings extends Model
{
    #[\Override]
    protected $fillable = [
        'gateway_url',
        'gateway_wg_ip',
        'ca_sha256',
        'ca_pem_path',
        'trusted_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'trusted_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        $record = self::query()->first();

        if ($record === null) {
            $record = self::query()->create([]);
        }

        return $record;
    }
}
