<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property array<string, mixed> $definition
 */
final class AptRepository extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 'name', 'definition'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array'];
    }
}
