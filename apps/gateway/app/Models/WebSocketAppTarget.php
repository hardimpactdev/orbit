<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Reverb app whose credentials an Instance's stored environment holds. An Instance holds at most one.
 *
 * @property int $id
 * @property int $websocket_app_id
 * @property int|null $route_id
 * @property-read Route|null $route
 * @property int $instance_id
 * @property-read WebSocketApp $webSocketApp
 * @property-read Instance $instance
 */
final class WebSocketAppTarget extends Model
{
    #[\Override]
    protected $table = 'websocket_app_targets';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'websocket_app_id',
        'instance_id',
        'route_id',
    ];

    /** @return BelongsTo<WebSocketApp, $this> */
    public function webSocketApp(): BelongsTo
    {
        return $this->belongsTo(WebSocketApp::class, 'websocket_app_id');
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
