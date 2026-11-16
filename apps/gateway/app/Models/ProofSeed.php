<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The seed of the proof default that one Node holds (docs/reference/development-containers.md#the-seed-on-each-ci-node): the holder
 * `proof-seed` and its volume `proof-seed-inner` in the nested Incus project (ProofSeeds::Holder), the commit and the time
 * that the default took the seed, and the seed copy that made it.
 *
 * @property int $id
 * @property int $node_id
 * @property string $holder
 * @property string $commit
 * @property Carbon $taken_at
 * @property int $operation_id
 */
final class ProofSeed extends Model
{
    /** @var list<string> */
    protected $fillable = ['node_id', 'holder', 'commit', 'taken_at', 'operation_id'];

    /** The holder's inner volume. */
    public function volume(): string
    {
        return $this->holder.'-inner';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['node_id' => 'integer', 'operation_id' => 'integer', 'taken_at' => 'datetime'];
    }
}
