<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\LifecycleStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property LifecycleStatus $status
 * @property string|null $installed_version
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property int|null $receipt the newest Operation whose receipt changed the manager's state
 * @property-read Node $node
 * @property-read Collection<int, Tool> $tools
 */
final class ToolManagerRecord extends Model
{
    /** @var string|null */
    #[\Override]
    protected $table = 'tool_managers';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'node_id',
        'name',
        'status',
        'installed_version',
        'failed_step',
        'error_code',
        'receipt',
    ];

    /**
     * Applies a manager state one Operation's receipt shows, unless a newer Operation's receipt already changed it: a late setup failure
     * never marks failed a manager a newer receipt proved active.
     *
     * @param  array<string, mixed>  $state
     */
    public function receive(int $operation, array $state): void
    {
        if ($operation >= ($this->receipt ?? 0)) {
            $this->update([...$state, 'receipt' => $operation]);
        }
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return HasMany<Tool, $this> */
    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class, 'tool_manager_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => LifecycleStatus::class,
        ];
    }
}
