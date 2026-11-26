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
 * @property int|null $suspected_by the newest admitted Operation that may write it
 */
final class AptRepository extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 'name', 'definition', 'verified', 'entries', 'suspected_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array', 'verified' => 'boolean', 'entries' => 'array'];
    }
}
