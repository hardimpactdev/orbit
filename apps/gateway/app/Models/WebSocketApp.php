<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\WebSocket\WebSocketCredentials;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Reverb app on the `websocket` role's server. Reverb keeps each app's channels apart and signs each
 * app's requests with its own secret, so one app's credentials never publish on another app's channels.
 * The model encrypts `secret` at rest and never serializes it.
 *
 * @property int $id
 * @property string $name
 * @property string $app_id
 * @property string $key
 * @property int $credential_generation
 * @property string $secret
 * @property-read Collection<int, WebSocketAppTarget> $targets
 */
final class WebSocketApp extends Model
{
    /** The app the Gateway itself publishes with. Its secret never leaves the Gateway. */
    public const string GatewayName = 'gateway';

    #[\Override]
    protected $table = 'websocket_apps';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'name',
        'app_id',
        'key',
        'secret',
        'credential_generation',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = [
        'secret',
    ];

    public function getRouteKeyName(): string
    {
        return 'name';
    }

    public function isGateway(): bool
    {
        return $this->name === self::GatewayName;
    }

    /** The public identifier in Pusher HTTP API paths. */
    public function pusherId(): string
    {
        return $this->app_id;
    }

    public function credentials(): WebSocketCredentials
    {
        return new WebSocketCredentials($this->app_id, $this->key, $this->secret);
    }

    /** @return array<array-key, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** @return HasMany<WebSocketAppTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(WebSocketAppTarget::class, 'websocket_app_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'credential_generation' => 'integer',
        ];
    }
}
