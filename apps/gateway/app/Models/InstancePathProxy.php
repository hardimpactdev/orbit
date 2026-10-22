<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $instance_id
 * @property int $route_id
 * @property int $process_id
 * @property int $port
 * @property string $prefix
 * @property bool $enabled
 * @property-read Route $route
 * @property-read Process $process
 */
final class InstancePathProxy extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['instance_id', 'route_id', 'process_id', 'port', 'prefix', 'enabled'];

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['port' => 'integer', 'enabled' => 'boolean'];
    }
}
