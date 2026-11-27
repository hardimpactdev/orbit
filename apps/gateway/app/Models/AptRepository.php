<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tools\AptRepositoryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property array<string, mixed> $definition
 * @property AptRepositoryStatus $status
 * @property string|null $error_code
 * @property int|null $operation_id
 * @property-read Node $node
 */
final class AptRepository extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 'name', 'definition', 'status', 'error_code', 'operation_id'];

    public function ready(): bool
    {
        return $this->status === AptRepositoryStatus::Ready;
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return HasMany<Tool, $this> */
    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array', 'status' => AptRepositoryStatus::class];
    }
}
