<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    const STATUS_PENDING = 'pending';

    const STATUS_DEPLOYING = 'deploying';

    const STATUS_ACTIVE = 'active';

    const STATUS_ERROR = 'error';

    protected $fillable = [
        'project_id',
        'environment_id',
        'status',
        'local_path',
        'site_url',
        'orchestrator_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @deprecated Use environment() instead
     */
    public function server(): BelongsTo
    {
        return $this->environment();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isDeploying(): bool
    {
        return $this->status === self::STATUS_DEPLOYING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }
}
