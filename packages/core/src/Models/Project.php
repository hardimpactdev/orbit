<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $environment_id
 * @property string $name
 * @property string|null $display_name
 * @property string $slug
 * @property string|null $path
 * @property string|null $php_version
 * @property string|null $github_repo
 * @property string|null $project_type
 * @property bool $has_public_folder
 * @property string|null $domain
 * @property string|null $url
 * @property string|null $status
 * @property string|null $error_message
 * @property string|null $job_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Project extends Model
{
    protected $casts = [
        'has_public_folder' => 'boolean',
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_CREATING_REPO = 'creating_repo';

    public const STATUS_CLONING = 'cloning';

    public const STATUS_SETTING_UP = 'setting_up';

    public const STATUS_INSTALLING_COMPOSER = 'installing_composer';

    public const STATUS_INSTALLING_NPM = 'installing_npm';

    public const STATUS_BUILDING = 'building';

    public const STATUS_FINALIZING = 'finalizing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'environment_id',
        'name',
        'display_name',
        'slug',
        'path',
        'php_version',
        'github_repo',
        'project_type',
        'has_public_folder',
        'domain',
        'url',
        'status',
        'error_message',
        'job_id',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function isProvisioning(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_CREATING_REPO,
            self::STATUS_CLONING,
            self::STATUS_SETTING_UP,
            self::STATUS_INSTALLING_COMPOSER,
            self::STATUS_INSTALLING_NPM,
            self::STATUS_BUILDING,
            self::STATUS_FINALIZING,
        ], true);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
