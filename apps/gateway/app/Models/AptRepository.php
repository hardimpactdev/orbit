<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property array<string, mixed> $definition
 * @property bool $verified
 * @property list<string>|null $entries every `uri suite` this source may have on disk
 */
final class AptRepository extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 'name', 'definition', 'verified', 'entries'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array', 'verified' => 'boolean', 'entries' => 'array'];
    }
}
