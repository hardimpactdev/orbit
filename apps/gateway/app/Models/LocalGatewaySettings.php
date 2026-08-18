<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $singleton_key
 * @property string|null $gateway_url
 * @property string|null $gateway_wg_ip
 * @property string|null $ca_sha256
 * @property string|null $ca_pem_path
 * @property Carbon|null $trusted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LocalGatewaySettings extends Model
{
    public const string SINGLETON_KEY = 'default';

    /**
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = [
        'singleton_key' => self::SINGLETON_KEY,
    ];

    #[\Override]
    protected $fillable = [
        'gateway_url',
        'gateway_wg_ip',
        'ca_sha256',
        'ca_pem_path',
        'trusted_at',
    ];

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (self $settings): void {
            $settings->singleton_key = self::SINGLETON_KEY;
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'trusted_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        try {
            return self::query()
                ->firstOrCreate(
                    ['singleton_key' => self::SINGLETON_KEY],
                );
        } catch (UniqueConstraintViolationException $exception) {
            $record = self::query()
                ->where('singleton_key', self::SINGLETON_KEY)
                ->first();

            if ($record instanceof self) {
                return $record;
            }

            throw $exception;
        }
    }
}
