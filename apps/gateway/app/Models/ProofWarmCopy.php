<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A warm proof copy that waits for a proof (docs/cli/proof/run.mdx#warm-copies): the task group that owns the copy, the copy's Node and
 * the seed of the proof default it was made from. A dropped one (`dropped_at`) waits for the destroy of its copy and is never taken.
 *
 * @property int $id
 * @property int $task_id
 * @property int $node_id
 * @property string $seed_commit
 * @property Carbon $seed_taken_at
 * @property Carbon|null $dropped_at
 */
final class ProofWarmCopy extends Model
{
    /** @var list<string> */
    protected $fillable = ['task_id', 'node_id', 'seed_commit', 'seed_taken_at', 'dropped_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['task_id' => 'integer', 'node_id' => 'integer', 'seed_taken_at' => 'datetime', 'dropped_at' => 'datetime'];
    }
}
