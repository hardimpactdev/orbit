<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $key
 * @property string|null $value
 * @property bool $secret
 * @property-read Workspace $workspace
 */
class WorkspaceEnvVariable extends Model
{
    #[\Override]
    protected $fillable = [
        'workspace_id',
        'key',
        'value',
        'secret',
    ];

    /**
     * @mago-expect lint:no-literal-password
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'secret' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
