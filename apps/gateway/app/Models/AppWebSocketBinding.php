<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AppWebSocketBindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $instance_id
 * @property bool $enabled
 * @property string $reverb_app_id
 * @property string $reverb_app_key
 * @property string $reverb_app_secret
 * @property list<string> $allowed_origins
 * @property list<string> $public_hosts
 * @property-read Instance $instance
 */
class AppWebSocketBinding extends Model
{
    /** @use HasFactory<AppWebSocketBindingFactory> */
    use HasFactory;

    #[Override]
    protected $table = 'app_websocket_bindings';

    #[Override]
    protected $fillable = [
        'instance_id',
        'enabled',
        'reverb_app_id',
        'reverb_app_key',
        'reverb_app_secret',
        'allowed_origins',
        'public_hosts',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'reverb_app_secret' => 'encrypted',
            'allowed_origins' => 'array',
            'public_hosts' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Instance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
